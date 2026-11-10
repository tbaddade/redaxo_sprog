<?php

declare(strict_types=1);

namespace Sprog\Boot;

use rex;
use rex_be_controller;
use rex_request;
use rex_response;
use rex_url;
use Sprog\Service\MigrationService;

/**
 * Führt Admins nach einem Update von 1.x auf die Migrationsseite (PAGES_PREPARED).
 *
 * Solange die v1 → v2 Migration aussteht, leitet jede Backend-Seite Admins
 * dorthin um — das Frontend liest nur noch v2, bis zur Migration fehlen dort
 * die Bestandstexte. Ausgenommen sind:
 *   - die Migrationsseite selbst (kein Loop),
 *   - die AddOn-Verwaltung (`packages`), damit Sprog deaktivierbar bleibt,
 *   - Nicht-GET-Requests (kein verlorenes Formular), API-Calls und Seiten
 *     ohne Layout (AJAX-/JSON-Endpunkte).
 *
 * Nicht-Admins können die admin-only Seite nicht öffnen → keine Weiterleitung,
 * sie sehen auf Dashboard/Inbox einen Hinweis.
 *
 * Ist die Migration erledigt, wird der Navigationspunkt ausgeblendet. Ein
 * direkter Aufruf zeigt dann nur noch „abgeschlossen".
 */
final class MigrationGate
{
    public const PAGE = 'sprog/datenpflege/migration';

    public static function handle(): void
    {
        $user = rex::getUser();
        if (null === $user || !$user->isAdmin()) {
            return;
        }

        if (!MigrationService::create()->isMigrationPending()) {
            rex_be_controller::getPageObject(self::PAGE)?->setHidden(true);

            return;
        }

        if (!self::mayRedirect()) {
            return;
        }

        rex_response::sendRedirect(rex_url::backendPage(self::PAGE));
    }

    private static function mayRedirect(): bool
    {
        if ('get' !== rex_request::requestMethod()) {
            return false;
        }
        if ('' !== rex_request('rex-api-call', 'string', '')) {
            return false;
        }

        $page = rex_be_controller::getCurrentPage();
        if (self::PAGE === $page || 'packages' === $page) {
            return false;
        }

        $pageObject = rex_be_controller::getCurrentPageObject();

        return null === $pageObject || $pageObject->hasLayout();
    }
}
