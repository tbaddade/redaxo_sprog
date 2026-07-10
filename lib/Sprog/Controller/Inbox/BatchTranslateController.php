<?php

declare(strict_types=1);

namespace Sprog\Controller\Inbox;

use rex_clang;
use rex_i18n;
use rex_user;
use Sprog\Enum\Status;
use Sprog\Http\JsonResponse;
use Sprog\Model\Unit;
use Sprog\Repository\TranslationRepository;
use Sprog\Repository\UnitRepository;
use Sprog\Service\GlossaryService;
use Sprog\Service\MtService;
use Sprog\Service\TranslationService;
use Sprog\Service\WorkflowService;
use Sprog\Support\BaseLang;
use Sprog\Support\ClangBase;
use Throwable;

use function array_filter;
use function array_map;
use function array_values;
use function count;
use function explode;
use function implode;
use function in_array;
use function strtolower;
use function trim;

/**
 * Endpoints für die Stapelverarbeitung (MT) im Inbox-UI:
 *   - POST ?func=batch_prepare   → Arbeitsliste (unit_ids) + Anzahl fehlender
 *                                   Übersetzungen einer Zielsprache
 *   - POST ?func=batch_translate → einen Chunk übersetzen und als Vorschlag ablegen
 *
 * Chunking treibt der Client (sprog.inbox.js): batch_prepare liefert die volle
 * Liste, batch_translate bekommt jeweils N unit_ids. So bleibt jeder Request
 * kurz genug (LLM-Latenz), der Fortschritt ist deterministisch.
 *
 * Nur Status „missing" wird übersetzt (kein Überschreiben vorhandener Werte,
 * Guard pro Eintrag). Ergebnis-Status „Prüfung nötig" — zweistufig, weil
 * missing→needs_review nicht direkt erlaubt ist (Fallback: Entwurf).
 */
final class BatchTranslateController
{
    public function __construct(
        private readonly UnitRepository $units,
        private readonly TranslationRepository $translations,
        private readonly TranslationService $service,
    ) {}

    public static function create(): self
    {
        return new self(new UnitRepository(), new TranslationRepository(), TranslationService::create());
    }

    /**
     * ?func=batch_prepare — unit_ids + Anzahl fehlender Übersetzungen der Zielsprache.
     */
    public function prepare(rex_user $user): never
    {
        [$targetClangId, $sourceClangId] = $this->assertTarget($user);

        // Nur übersetzbare Einträge: Ziel „missing" UND Quelltext (Basissprache)
        // vorhanden. Einträge ohne Quelltext werden gar nicht erst versucht;
        // ihre Anzahl geht als Hinweis an den Client.
        $unitIds = $this->translations->findTranslatableUnitIds($targetClangId, $sourceClangId, Status::Missing);
        $missingTotal = count($this->translations->findUnitIdsByClangAndStatus($targetClangId, Status::Missing));

        JsonResponse::ok([
            'total' => count($unitIds),
            'unitIds' => array_values($unitIds),
            'withoutSource' => max(0, $missingTotal - count($unitIds)),
        ]);
    }

    /**
     * ?func=batch_translate — einen Chunk (unit_ids) übersetzen.
     */
    public function translate(rex_user $user): never
    {
        [$targetClangId, $sourceClangId] = $this->assertTarget($user);

        $unitIds = $this->parseUnitIds((string) rex_request('unit_ids', 'string', ''));
        if ([] === $unitIds) {
            JsonResponse::badRequest(rex_i18n::rawMsg('sprog_inbox_batch_bad_request'));
        }

        // Provider: expliziter, echter Provider aus der Whitelist (kein noop).
        $provider = trim((string) rex_request('provider', 'string', ''));
        $mt = MtService::create();
        if ('' === $provider || 'noop' === $provider || !in_array($provider, $mt->configuredProviderNames(), true)) {
            JsonResponse::badRequest(rex_i18n::rawMsg('sprog_inbox_batch_no_provider'));
        }

        $sourceClang = rex_clang::get($sourceClangId);
        $targetClang = rex_clang::get($targetClangId);
        if (null === $sourceClang || null === $targetClang) {
            JsonResponse::badRequest(rex_i18n::rawMsg('sprog_inbox_batch_bad_lang'));
        }
        $sourceCode = strtolower($sourceClang->getCode());
        $targetCode = strtolower($targetClang->getCode());

        // Glossar ist pro Sprachrichtung identisch → einmal laden statt pro Eintrag.
        $glossary = GlossaryService::create()->mapForPair($sourceClangId, $targetClangId);
        $workflow = WorkflowService::create();
        $userId = $user->getId();

        // Bulk-Aktion: mehrere LLM-Calls pro Request → Zeitlimit anheben.
        set_time_limit(300);

        $results = [];
        foreach ($unitIds as $unitId) {
            $results[] = $this->translateOne(
                $mt,
                $workflow,
                $user,
                $userId,
                $unitId,
                $targetClangId,
                $sourceClangId,
                $sourceCode,
                $targetCode,
                $provider,
                $glossary,
            );
        }

        JsonResponse::ok(['results' => $results]);
    }

    /**
     * Übersetzt einen einzelnen Eintrag. Fehler/Übersprünge werden als Status
     * zurückgegeben, damit ein Ausfall den restlichen Chunk nicht abbricht.
     *
     * @param array<string, string> $glossary
     * @return array{unitId: int, status: string, error?: string}
     */
    private function translateOne(
        MtService $mt,
        WorkflowService $workflow,
        rex_user $user,
        int $userId,
        int $unitId,
        int $targetClangId,
        int $sourceClangId,
        string $sourceCode,
        string $targetCode,
        string $provider,
        array $glossary,
    ): array {
        $unit = null;
        try {
            $unit = $this->units->find($unitId);
            if (null === $unit) {
                return ['unitId' => $unitId, 'status' => 'skipped'];
            }

            // Kein Überschreiben: nur wenn der Ziel-Eintrag noch wirklich leer ist
            // (idempotent gegen Doppel-Läufe / zwischenzeitliche Bearbeitung).
            $target = $this->translations->findForUnitAndClang($unitId, $targetClangId);
            if (null !== $target && Status::Missing !== $target->status) {
                return ['unitId' => $unitId, 'status' => 'skipped'];
            }

            $source = $this->translations->findForUnitAndClang($unitId, $sourceClangId);
            if (null === $source || '' === trim($source->value)) {
                return ['unitId' => $unitId, 'status' => 'skipped'];
            }

            $result = $mt->translate($source->value, $sourceCode, $targetCode, $provider, $glossary, $this->buildContext($unit));

            $saved = $this->service->updateValue(
                $unit,
                $targetClangId,
                $result->text,
                $userId,
                mtProvider: $result->provider,
                mtConfidence: $result->confidence,
                origin: 'mt',
            );

            // Ziel „Prüfung nötig": updateValue hat auf Entwurf gesetzt, jetzt
            // draft→needs_review — aber nur, wenn der Nutzer einreichen darf.
            // Sonst bleibt der Eintrag als Entwurf stehen (gracefuller Fallback).
            if (
                Status::NeedsReview !== $saved->status
                && $workflow->mayTransitionTo($saved->status, Status::NeedsReview, $user, $targetClangId, $userId, $saved->translatorId)
            ) {
                try {
                    $this->service->transition((int) $saved->id, Status::NeedsReview, $userId);
                } catch (Throwable) {
                    // Übergang fehlgeschlagen (z.B. Lock) — Entwurf ist ok.
                }
            }

            return ['unitId' => $unitId, 'key' => $unit->unitKey, 'status' => 'ok'];
        } catch (Throwable $e) {
            return [
                'unitId' => $unitId,
                'key' => null !== $unit ? $unit->unitKey : null,
                'status' => 'failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Validiert Zielsprache + Berechtigung + CSRF.
     *
     * @return array{0: int, 1: int} [targetClangId, sourceClangId]
     */
    private function assertTarget(rex_user $user): array
    {
        JsonResponse::ensureCsrf('sprog_inbox_save', rex_i18n::rawMsg('sprog_inbox_save_csrf'));

        $targetClangId = (int) rex_request('clang_id', 'int', 0);
        $sourceClangId = BaseLang::clangId();

        // Ziel muss existieren und darf nicht die Quell-Sprache sein.
        if (!rex_clang::exists($targetClangId) || $targetClangId === $sourceClangId) {
            JsonResponse::badRequest(rex_i18n::rawMsg('sprog_inbox_batch_bad_lang'));
        }
        // Abgeleitete Sprache (clang_base → andere Sprache) spiegelt eine andere
        // und wird nicht eigenständig übersetzt — kein gültiges Batch-Ziel.
        if (ClangBase::isDerived($targetClangId)) {
            JsonResponse::badRequest(rex_i18n::rawMsg('sprog_inbox_target_derived'));
        }
        if (!WorkflowService::create()->canEdit($user, $targetClangId)) {
            JsonResponse::forbidden(rex_i18n::rawMsg('sprog_inbox_save_no_perm'));
        }

        return [$targetClangId, $sourceClangId];
    }

    /**
     * @return list<int>
     */
    private function parseUnitIds(string $raw): array
    {
        return array_values(array_filter(
            array_map(static fn (string $s): int => (int) trim($s), explode(',', $raw)),
            static fn (int $id): bool => $id > 0,
        ));
    }

    /**
     * Kontext-Hinweis aus Bereich + Notiz der Einheit (gleiche Logik wie
     * MtController) — der KI-Provider verwertet ihn im Prompt.
     */
    private function buildContext(Unit $unit): ?string
    {
        $parts = [];
        if ('' !== $unit->context) {
            $parts[] = 'Bereich: ' . $unit->context;
        }
        if (null !== $unit->notes && '' !== trim($unit->notes)) {
            $parts[] = 'Hinweis: ' . $unit->notes;
        }

        return [] === $parts ? null : implode('. ', $parts);
    }
}
