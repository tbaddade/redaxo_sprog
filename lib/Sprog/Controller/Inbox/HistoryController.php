<?php

declare(strict_types=1);

namespace Sprog\Controller\Inbox;

use rex_clang;
use rex_i18n;
use rex_user;
use Sprog\Enum\Status;
use Sprog\Exception\OptimisticLockException;
use Sprog\Http\JsonResponse;
use Sprog\Model\Translation;
use Sprog\Repository\TranslationHistoryRepository;
use Sprog\Repository\TranslationRepository;
use Sprog\Repository\UnitRepository;
use Sprog\Service\TranslationService;
use Sprog\Service\WorkflowService;
use Sprog\Support\Labels;
use Sprog\View\InboxRowActions;
use Throwable;

/**
 * Endpoints für die Übersetzungs-Historie im Inbox-Akkordeon:
 *
 *   - POST ?func=history  → Versionsliste einer Übersetzung (lesen)
 *   - POST ?func=restore  → eine frühere Version wiederherstellen (schreiben)
 *
 * Restore ist bewusst kein Sonderweg: es reicht den alten Wert durch den
 * normalen TranslationService::updateValue()-Pfad (inkl. Optimistic-Lock und
 * Aktivitäts-/Historie-Aufzeichnung) — die Wiederherstellung wird dadurch
 * selbst wieder eine neue Version (append-only, nicht destruktiv).
 *
 * CSRF nutzt das page-globale sprog_inbox_save-Token; Bearbeiten setzt eine
 * Übersetzer-/Reviewer-Rolle voraus (WorkflowService::canEdit()).
 */
final class HistoryController
{
    /** Maximale Anzahl gelisteter Versionen. */
    private const LIST_LIMIT = 50;

    public function __construct(
        private readonly UnitRepository $units,
        private readonly TranslationRepository $translations,
        private readonly TranslationHistoryRepository $history,
        private readonly TranslationService $service,
    ) {}

    public static function create(): self
    {
        return new self(
            new UnitRepository(),
            new TranslationRepository(),
            new TranslationHistoryRepository(),
            TranslationService::create(),
        );
    }

    /**
     * ?func=history — Versionsliste (neueste zuerst) einer Übersetzung.
     */
    public function list(rex_user $user): never
    {
        $unitId = (int) rex_request('unit_id', 'int', 0);
        $clangId = (int) rex_request('clang_id', 'int', 0);

        JsonResponse::ensureCsrf('sprog_inbox_save', rex_i18n::rawMsg('sprog_inbox_save_csrf'));

        $translation = $this->resolveTranslation($unitId, $clangId, $user);

        $entries = $this->history->listForTranslation((int) $translation->id, self::LIST_LIMIT);

        $versions = [];
        foreach ($entries as $entry) {
            $status = Status::tryFrom($entry->status);
            $versions[] = [
                'id' => $entry->id,
                'value' => $entry->value,
                'origin' => $entry->origin,
                'mt_provider' => $entry->mtProvider,
                'status' => $entry->status,
                'statusLabel' => null !== $status ? Labels::status($status) : $entry->status,
                'user' => $this->userName($entry->userId),
                'created_at' => $entry->createdAt->format('c'),
                // Aktuelle Version = wertgleich mit dem Live-Stand → im UI nicht
                // wiederherstellbar (wäre ein No-Op).
                'isCurrent' => $entry->value === $translation->value,
            ];
        }

        JsonResponse::ok(['versions' => $versions]);
    }

    /**
     * ?func=restore — stellt die Version mit history_id als neuen Wert her.
     */
    public function restore(rex_user $user): never
    {
        $unitId = (int) rex_request('unit_id', 'int', 0);
        $clangId = (int) rex_request('clang_id', 'int', 0);
        $historyId = (int) rex_request('history_id', 'int', 0);
        $expectedRevision = (int) rex_request('revision', 'int', 0);

        JsonResponse::ensureCsrf('sprog_inbox_save', rex_i18n::rawMsg('sprog_inbox_save_csrf'));

        $translation = $this->resolveTranslation($unitId, $clangId, $user);

        $entry = $historyId > 0 ? $this->history->find($historyId) : null;
        // Zugehörigkeit prüfen: die History-Zeile muss zu genau dieser
        // Übersetzung gehören (kein Restore fremder Werte über manipulierte IDs).
        if (null === $entry || $entry->translationId !== (int) $translation->id) {
            JsonResponse::badRequest(rex_i18n::rawMsg('sprog_inbox_history_not_found'));
        }

        $unit = $this->units->find($unitId);
        if (null === $unit) {
            JsonResponse::notFound(rex_i18n::rawMsg('sprog_inbox_save_unit_missing'));
        }

        $workflow = WorkflowService::create();

        try {
            $saved = $this->service->updateValue(
                $unit,
                $clangId,
                $entry->value,
                $user->getId(),
                mtProvider: $entry->mtProvider,
                mtConfidence: $entry->mtConfidence,
                expectedRevision: $expectedRevision,
                origin: 'restore',
            );
        } catch (OptimisticLockException) {
            JsonResponse::conflict(rex_i18n::rawMsg('sprog_inbox_save_conflict'));
        } catch (Throwable $e) {
            JsonResponse::internalError($e->getMessage());
        }

        // Fertig gerenderte Aktionszeile für den wiederhergestellten Stand — das
        // JS tauscht sie 1:1 ein (identisch zum Reload). canEdit ist durch
        // resolveTranslation() bereits sichergestellt.
        $rowActionsHtml = InboxRowActions::render($workflow, $user, $clangId, $user->getId(), $saved->status, $saved, true);

        JsonResponse::ok([
            'value' => $saved->value,
            'revision' => $saved->revision,
            'status' => $saved->status->value,
            'statusLabel' => Labels::status($saved->status),
            'value_hash' => $saved->valueHash,
            'updatedAt' => null !== $saved->updatedAt ? $saved->updatedAt->format('c') : null,
            'rowActionsHtml' => $rowActionsHtml,
        ]);
    }

    /**
     * Lädt die Übersetzung zu (unit, clang), validiert Request und Berechtigung.
     * Sendet bei Fehlern direkt eine JSON-Antwort (never-Rückkehr).
     */
    private function resolveTranslation(int $unitId, int $clangId, rex_user $user): Translation
    {
        if ($unitId <= 0 || !rex_clang::exists($clangId)) {
            JsonResponse::badRequest(rex_i18n::rawMsg('sprog_inbox_save_bad_request'));
        }
        if (!WorkflowService::create()->canEdit($user, $clangId)) {
            JsonResponse::forbidden(rex_i18n::rawMsg('sprog_inbox_save_no_perm'));
        }

        $translation = $this->translations->findForUnitAndClang($unitId, $clangId);
        if (null === $translation || null === $translation->id) {
            JsonResponse::notFound(rex_i18n::rawMsg('sprog_inbox_history_not_found'));
        }

        return $translation;
    }

    private function userName(?int $userId): ?string
    {
        if (null === $userId) {
            return null;
        }

        $u = rex_user::get($userId);
        if (null === $u) {
            return null;
        }

        $name = (string) $u->getValue('name');

        return '' !== $name ? $name : $u->getLogin();
    }
}
