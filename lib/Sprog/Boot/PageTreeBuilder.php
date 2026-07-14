<?php

declare(strict_types=1);

namespace Sprog\Boot;

use rex;
use rex_be_controller;
use rex_config;
use rex_user;

/**
 * Persistiert die Sprachbasis (`clang_base`) auf PAGES_PREPARED.
 *
 * Läuft vor dem Page-Render, damit ein gerade gespeicherter `clang_base`-Wert
 * im selben Request schon den Page-Tree beeinflusst (welche Sprachen als
 * eigenständig übersetzbar in der Navigation erscheinen).
 *
 * Früher baute diese Klasse zusätzlich die clang-spezifischen Wildcard-/
 * Abbreviation-Subpages; diese v1-Seiten sind in v2 entfallen — die Pflege
 * läuft jetzt über die Inbox.
 */
final class PageTreeBuilder
{
    public static function publish(): void
    {
        $user = rex::getUser();
        if (null === $user) {
            return;
        }

        self::handleSettingsUpdate($user);
    }

    /**
     * `clang_base` (Sprachbasis) wird geschrieben, bevor das Page-HTML rendert.
     * Bewusst hier in PAGES_PREPARED — vor dem eigentlichen Page-Render, damit
     * der geänderte Wert im selben Request schon den Page-Tree beeinflusst
     * (welche Sprachen als eigenständig übersetzbar in der Navigation
     * erscheinen).
     *
     * Nur beim Speichern der Sprachen-Sektion (`settings_section=languages`) —
     * andere Sektionen der Konfigurations-Seite senden keine `clang_base`-Felder
     * und würden den Wert sonst mit einem leeren Array überschreiben.
     */
    private static function handleSettingsUpdate(rex_user $user): void
    {
        if (!$user->isAdmin()) {
            return;
        }
        if ('sprog/settings' !== rex_be_controller::getCurrentPage()) {
            return;
        }
        if ('languages' !== rex_request('settings_section', 'string')) {
            return;
        }

        rex_config::set('sprog', 'clang_base', rex_request('clang_base', 'array'));
    }
}
