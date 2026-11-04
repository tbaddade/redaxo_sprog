<?php

declare(strict_types=1);

namespace Sprog\Controller\Inbox;

use rex_i18n;
use rex_sql;
use rex_user;
use Sprog\Http\JsonResponse;
use Sprog\Repository\TranslationHistoryRepository;
use Sprog\Repository\TranslationRepository;
use Sprog\Repository\UnitRepository;
use Sprog\Service\ActivityService;
use Throwable;

/**
 * Endpoint POST ?func=delete_unit — löscht einen Platzhalter (Unit) samt aller
 * Übersetzungen und Historien-Snapshots aus dem Inbox-Modal (Löschen-Button im
 * Edit-Mode).
 *
 * Gleiche Permission wie das Bearbeiten (`sprog[unit_edit]`; Admin geht immer
 * durch) und derselbe CSRF-Token wie update_unit (`sprog_inbox_save`).
 *
 * REDAXO setzt keine DB-Cascades, deshalb räumt der Controller die abhängigen
 * Tabellen selbst ab — in Kind-vor-Eltern-Reihenfolge: erst die Historie, dann
 * die Translations, dann die Unit. Der Audit-Eintrag (unit.deleted) wird bewusst
 * NACH dem Löschen geschrieben und bleibt als append-only Nachweis erhalten;
 * seine unit_id ist dann ein verwaister Backref, was für ein Audit-Log gewollt
 * ist.
 */
final class DeleteUnitController
{
    public function __construct(
        private readonly UnitRepository $units,
        private readonly TranslationRepository $translations,
        private readonly TranslationHistoryRepository $history,
        private readonly ActivityService $activity,
    ) {}

    public static function create(): self
    {
        return new self(
            new UnitRepository(),
            new TranslationRepository(),
            new TranslationHistoryRepository(),
            ActivityService::create(),
        );
    }

    public function handle(rex_user $user): never
    {
        $unitId = (int) rex_request('unit_id', 'int', 0);

        JsonResponse::ensureCsrf('sprog_inbox_save', rex_i18n::rawMsg('sprog_inbox_save_csrf'));

        if (!($user->isAdmin() || $user->hasPerm('sprog[unit_edit]'))) {
            JsonResponse::forbidden(rex_i18n::rawMsg('sprog_inbox_unit_edit_no_perm'));
        }

        $unit = $this->units->find($unitId);
        if (null === $unit) {
            JsonResponse::notFound(rex_i18n::rawMsg('sprog_inbox_save_unit_missing'));
        }

        try {
            // Alle Repositories teilen sich die rex_sql-Verbindung (DB 1) — die
            // Transaktion umschließt also History, Translations, Unit und den
            // Audit-Eintrag gemeinsam. Bricht ein Schritt ab, bleibt nichts halb
            // gelöscht zurück.
            rex_sql::factory()->transactional(function () use ($unitId, $unit, $user): void {
                $this->history->deleteByUnit($unitId);
                $this->translations->deleteByUnit($unitId);
                $this->units->delete($unitId);
                $this->activity->logUnitDeleted($unitId, $user->getId(), $unit->namespace, $unit->unitKey);
            });
        } catch (Throwable $e) {
            JsonResponse::internalError($e->getMessage());
        }

        JsonResponse::ok([
            'unit_id' => $unit->id,
        ]);
    }
}
