<?php

declare(strict_types=1);

namespace Sprog\Boot;

use rex;
use rex_addon_interface;
use rex_be_controller;
use rex_be_page;
use rex_clang;
use rex_config;
use rex_path;
use rex_user;

/**
 * Baut die clang-spezifischen Subpages im Backend-Page-Tree.
 *
 * Hängt auf PAGES_PREPARED — vor dem Render, nachdem package.yml verarbeitet
 * wurde. Die package.yml-Struktur legt nur die Root-Pages (`sprog/wildcard`,
 * `sprog/abbreviation`, …) an; pro rex_clang muss dynamisch eine Subpage
 * eingehängt werden, damit der User die Sprache wechseln kann ohne die Page
 * neu zu laden. clang-Permissions filtern Subpages weg, auf die der User
 * keinen Zugriff hat.
 *
 * Bisher als 90-LOC-Closure inline in boot.php; hier in kleinere, einzeln
 * testbare static-Methoden zerlegt.
 */
final class PageTreeBuilder
{
    public static function publish(rex_addon_interface $addon): void
    {
        $user = rex::getUser();
        if (null === $user) {
            return;
        }

        self::handleSettingsUpdate($user);
        self::buildAbbreviationSubpages($user);
        self::buildWildcardSubpages($user);
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

    private static function buildAbbreviationSubpages(rex_user $user): void
    {
        if (!($user->isAdmin() || $user->hasPerm('sprog[abbreviation]'))) {
            return;
        }

        // getPageObject() liefert NULL, wenn die Subpage nicht (mehr) im Tree
        // ist — z.B. wenn das Addon aktiv aber nicht installiert ist. Vorher
        // hat boot.php hier mit "Call to a member function addSubpage() on
        // null" gecrasht.
        $page = rex_be_controller::getPageObject('sprog/abbreviation');
        if (null === $page) {
            return;
        }

        $currentClangId = (int) str_replace('clang', '', (string) rex_be_controller::getCurrentPagePart(3, ''));

        foreach (rex_clang::getAll() as $id => $clang) {
            if (!$user->getComplexPerm('clang')->hasPerm($id)) {
                continue;
            }
            $bePage = new rex_be_page('clang' . $id, $clang->getName());
            $bePage->setSubPath(rex_path::addon('sprog', 'pages/abbreviation.php'));
            $bePage->setIsActive($id === $currentClangId);
            $page->addSubpage($bePage);
        }
    }

    private static function buildWildcardSubpages(rex_user $user): void
    {
        if (!($user->isAdmin() || $user->hasPerm('sprog[wildcard]'))) {
            return;
        }

        $page = rex_be_controller::getPageObject('sprog/wildcard');
        if (null === $page) {
            return;
        }

        // Alle Sprachen in einem Formular (pages/wildcard.clang_all.php). Das
        // frühere „Sprachen als Unternavigation"-Layout (clang_switch) samt der
        // dynamisch pro Sprache eingehängten Subpages ist entfallen.
        $page->setSubPath(rex_path::addon('sprog', 'pages/wildcard.clang_all.php'));
    }
}
