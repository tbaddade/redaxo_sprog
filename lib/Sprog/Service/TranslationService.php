<?php

declare(strict_types=1);

namespace Sprog\Service;

use InvalidArgumentException;
use rex_clang;
use Sprog\Enum\Status;
use Sprog\Exception\OptimisticLockException;
use Sprog\Model\Translation;
use Sprog\Model\Unit;
use Sprog\Repository\TranslationRepository;
use Sprog\Repository\UnitRepository;
use Sprog\Support\ContentHash;

/**
 * Business-Logic für Übersetzungen.
 *
 * Kapselt:
 *   - Status-Übergänge (mit Whitelist welche Übergänge erlaubt sind)
 *   - Hash-Berechnung (über ContentHash, nie vom Caller)
 *   - Stale-Detection beim Quell-Änderungs-Workflow
 *   - Audit-Logging (über ActivityService) für jede schreibende Aktion
 *   - Optimistic-Locking: $expectedRevision-Parameter in updateValue() und
 *     transition() löst saveWithLock() im Repository aus.
 *
 * Aktiv NICHT in dieser Klasse:
 *   - Berechtigungs-Checks (sind Aufgabe der aufrufenden Page/REST-Schicht)
 */
final class TranslationService
{
    public function __construct(
        private readonly TranslationRepository $translations,
        private readonly UnitRepository $units,
        private readonly ActivityService $activity,
    ) {
    }

    /**
     * Convenience-Factory, falls kein DI-Container im Aufrufer existiert.
     */
    public static function create(): self
    {
        return new self(
            new TranslationRepository(),
            new UnitRepository(),
            ActivityService::create(),
        );
    }

    public function find(int $unitId, int $clangId): ?Translation
    {
        return $this->translations->findForUnitAndClang($unitId, $clangId);
    }

    /**
     * Stellt sicher, dass für (unit, clang) eine Translation-Row existiert.
     * Wenn nicht: legt eine mit Status=missing an.
     *
     * Idempotent: mehrfacher Aufruf hat keine Nebeneffekte ausser Lese-Last.
     * Bewusst kein Activity-Eintrag — ensureMissing ist eine technische
     * Hilfsfunktion, nicht eine Nutzer-Aktion.
     */
    public function ensureMissing(Unit $unit, int $clangId): Translation
    {
        if (null === $unit->id) {
            throw new InvalidArgumentException('Unit muss persistiert sein (id != null).');
        }

        $existing = $this->translations->findForUnitAndClang($unit->id, $clangId);
        if (null !== $existing) {
            return $existing;
        }

        $fresh = new Translation(
            id:                       null,
            unitId:                   $unit->id,
            clangId:                  $clangId,
            value:                    '',
            valueHash:                null,
            sourceHashAtTranslation:  null,
            status:                   Status::Missing,
            mtProvider:               null,
            mtConfidence:             null,
            translatorId:             null,
            reviewerId:               null,
            revision:                 0,
        );

        return $this->translations->save($fresh);
    }

    /**
     * Legt für $unit fehlende Translation-Rows in allen (oder den angegebenen)
     * Sprachen an. Bestehende Rows bleiben unangetastet.
     *
     * Hintergrund: alle Code-Pfade, die eine Unit anlegen (Create-Page,
     * v1→v2 Migration, ggf. später API), müssen für *jede* definierte clang
     * eine Row haben — sonst findet der Inbox-Filter „missing in clang X"
     * eine Unit, die in clang X gar keine Row besitzt, nicht. Drift zwischen
     * Units mit voller / partieller Row-Abdeckung ist die Folge.
     *
     * Standard: alle clangs (auch offline). Caller kann eine Teilmenge
     * übergeben, z.B. wenn nur ausgewählte clangs initialisiert werden sollen.
     *
     * @param array<int, int>|null $clangIds  null = alle clangs (inkl. offline)
     * @return int Anzahl der tatsächlich neu eingefügten Rows
     */
    public function ensureRowsForUnit(Unit $unit, ?array $clangIds = null): int
    {
        if (null === $unit->id) {
            throw new InvalidArgumentException('Unit muss persistiert sein (id != null).');
        }

        $targets = $clangIds ?? rex_clang::getAllIds(false);
        $created = 0;

        foreach ($targets as $clangId) {
            $existing = $this->translations->findForUnitAndClang($unit->id, (int) $clangId);
            if (null !== $existing) {
                continue;
            }
            $this->ensureMissing($unit, (int) $clangId);
            $created++;
        }

        return $created;
    }

    /**
     * Setzt den übersetzten Wert und transitioniert den Status.
     *
     * Defaults für Status nach Wert-Update:
     *   - Wenn der neue Wert leer ist:                       → Missing (Reset)
     *   - Wenn der bisherige Status 'missing' war:           → Draft
     *   - Wenn der bisherige Status 'stale' war:             → Translated (Auffrischung erledigt)
     *   - Sonst                                              → bleibt unverändert
     *
     * $targetStatus kann das explizit überschreiben (z.B. UI-Aktion "Speichern und Review anfordern").
     *
     * Hash-Berechnung passiert intern; der Caller liefert nur den Klartext.
     * Audit-Log wird automatisch geschrieben (translation.updated).
     */
    public function updateValue(
        Unit $unit,
        int $clangId,
        string $value,
        ?int $userId,
        ?Status $targetStatus = null,
        ?string $mtProvider = null,
        ?float $mtConfidence = null,
        ?int $expectedRevision = null,
    ): Translation {
        if (null === $unit->id) {
            throw new InvalidArgumentException('Unit muss persistiert sein (id != null).');
        }
        if (null !== $mtConfidence && ($mtConfidence < 0.0 || $mtConfidence > 1.0)) {
            throw new InvalidArgumentException('mtConfidence muss im Bereich [0.0, 1.0] liegen.');
        }

        $current = $this->translations->findForUnitAndClang($unit->id, $clangId);

        // Lock-Vorprüfung: wenn der Caller eine erwartete Revision mitschickt,
        // muss sie sowohl existieren als auch übereinstimmen. So gibt es einen
        // klaren Konflikt-Fehler, bevor wir Hash-Berechnungen anstellen. Das
        // finale Locking läuft trotzdem atomar in saveWithLock().
        if (null !== $expectedRevision) {
            if (null === $current) {
                throw new OptimisticLockException(0, $expectedRevision);
            }
            if ($current->revision !== $expectedRevision) {
                throw new OptimisticLockException((int) $current->id, $expectedRevision);
            }
        }

        if (null === $current) {
            $current = $this->ensureMissing($unit, $clangId);
        }

        $nextStatus = $targetStatus ?? $this->autoStatusForUpdate($current->status, $value);

        // Reset-Pfad: leerer Wert + Ziel-Status Missing umgeht die Transition-
        // Whitelist. Das ist semantisch eine Re-Initialisierung der Übersetzung
        // (kein Workflow-Schritt), und Translated/Approved/etc. → Missing wäre
        // ohne diese Sonderbehandlung blockiert.
        if (!('' === $value && Status::Missing === $nextStatus)) {
            $this->assertCanTransition($current->status, $nextStatus);
        }

        $next = new Translation(
            id:                       $current->id,
            unitId:                   $current->unitId,
            clangId:                  $current->clangId,
            value:                    $value,
            valueHash:                '' === $value ? null : ContentHash::of($value),
            sourceHashAtTranslation:  $unit->sourceHash,
            status:                   $nextStatus,
            mtProvider:               $mtProvider,
            mtConfidence:             $mtConfidence,
            translatorId:             $userId ?? $current->translatorId,
            reviewerId:               $current->reviewerId,
            revision:                 $current->revision,
        );

        $saved = null !== $expectedRevision
            ? $this->translations->saveWithLock($next)
            : $this->translations->save($next);

        $this->activity->logTranslationUpdated(
            unitId:        $saved->unitId,
            translationId: (int) $saved->id,
            userId:        $userId,
            oldStatus:     $current->status,
            newStatus:     $saved->status,
            oldValueHash:  $current->valueHash,
            newValueHash:  $saved->valueHash,
        );

        // Wenn die Aktion MT-induziert war, zusätzlich einen MT-Draft-Eintrag.
        // So lässt sich später leicht zählen "wie viele Drafts kamen von Provider X".
        if (null !== $mtProvider && Status::Draft === $saved->status) {
            $this->activity->logMtDraftCreated(
                unitId:        $saved->unitId,
                translationId: (int) $saved->id,
                userId:        $userId,
                provider:      $mtProvider,
                confidence:    $mtConfidence,
            );
        }

        return $saved;
    }

    /**
     * Reiner Status-Wechsel ohne Wert-Änderung — z.B. "Approve",
     * "Zur Review setzen", "Re-Open".
     *
     * Idempotenter Aufruf (from == to) erzeugt keinen Audit-Eintrag.
     */
    public function transition(
        int $translationId,
        Status $newStatus,
        ?int $userId,
        ?int $expectedRevision = null,
    ): Translation {
        $current = $this->translations->find($translationId);
        if (null === $current) {
            throw new InvalidArgumentException('Translation ' . $translationId . ' nicht gefunden.');
        }

        // Lock-Vorprüfung (siehe updateValue).
        if (null !== $expectedRevision && $current->revision !== $expectedRevision) {
            throw new OptimisticLockException($translationId, $expectedRevision);
        }

        $this->assertCanTransition($current->status, $newStatus);

        if ($current->status === $newStatus) {
            return $current;
        }

        // Reviewer-ID nur setzen, wenn die Transition tatsächlich eine
        // Review-Aktion ist. So bleibt nachvollziehbar, wer wann freigegeben hat.
        $reviewerId = $current->reviewerId;
        if (Status::Approved === $newStatus || Status::NeedsReview === $newStatus) {
            $reviewerId = $userId ?? $reviewerId;
        }

        $next = new Translation(
            id:                       $current->id,
            unitId:                   $current->unitId,
            clangId:                  $current->clangId,
            value:                    $current->value,
            valueHash:                $current->valueHash,
            sourceHashAtTranslation:  $current->sourceHashAtTranslation,
            status:                   $newStatus,
            mtProvider:               $current->mtProvider,
            mtConfidence:             $current->mtConfidence,
            translatorId:             $current->translatorId,
            reviewerId:               $reviewerId,
            revision:                 $current->revision,
        );

        $saved = null !== $expectedRevision
            ? $this->translations->saveWithLock($next)
            : $this->translations->save($next);

        $this->activity->logStatusTransition(
            unitId:        $saved->unitId,
            translationId: (int) $saved->id,
            userId:        $userId,
            from:          $current->status,
            to:            $saved->status,
        );

        return $saved;
    }

    /**
     * Quell-Wert hat sich geändert: aktualisiert source_hash der Unit und
     * markiert alle finalen Übersetzungen als 'stale', damit Übersetzer
     * darüber stolpern.
     *
     * @return int Anzahl der als stale markierten Übersetzungen
     */
    public function markSourceChanged(Unit $unit, ?string $newSourceHash, ?int $userId = null): int
    {
        if (null === $unit->id) {
            throw new InvalidArgumentException('Unit muss persistiert sein (id != null).');
        }

        // Idempotent: wenn der Hash sich gar nicht geändert hat, gibt's
        // auch keine stale-Markierung und keinen Audit-Eintrag.
        if (ContentHash::equals($unit->sourceHash, $newSourceHash)) {
            return 0;
        }

        $this->units->updateSourceHash($unit->id, $newSourceHash);
        $staleCount = $this->translations->markStaleForUnit($unit->id);

        $this->activity->logSourceChanged(
            unitId:     $unit->id,
            userId:     $userId,
            oldHash:    $unit->sourceHash,
            newHash:    $newSourceHash,
            staleCount: $staleCount,
        );

        return $staleCount;
    }

    /**
     * Validiert einen Status-Übergang gegen die Whitelist im Status-Enum.
     * Wirft `InvalidArgumentException`, wenn der Übergang nicht erlaubt ist.
     *
     * Die Whitelist selbst lebt in `Status::allowedNextStates()` — Single
     * source of truth, von der auch die UI-Buttons (über `userActions()`)
     * abgeleitet sind.
     */
    private function assertCanTransition(Status $from, Status $to): void
    {
        if ($from->canTransitionTo($to)) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'Status-Übergang "%s" → "%s" ist nicht erlaubt.',
            $from->value,
            $to->value,
        ));
    }

    private function autoStatusForUpdate(Status $current, string $value): Status
    {
        // Leerer Wert ist semantisch "keine Übersetzung" — egal aus welchem
        // Status wir kommen, der saubere Folge-Status ist Missing. Sonst hätten
        // wir "Draft mit leerem Inhalt", was im UI/Filter Verwirrung stiftet.
        if ('' === $value) {
            return Status::Missing;
        }

        return match ($current) {
            Status::Missing => Status::Draft,
            Status::Stale   => Status::Translated,
            // Reviewer hat Überarbeitung angefordert — sobald der Übersetzer
            // den Wert antippt, geht's zurück in den aktiven Bearbeitungs-Pfad.
            Status::Revise  => Status::Draft,
            default         => $current,
        };
    }
}
