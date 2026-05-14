<?php

declare(strict_types=1);

namespace Sprog\Boot;

use rex;
use rex_addon;
use rex_be_controller;
use rex_be_page;
use rex_clang;
use rex_config;
use rex_path;
use rex_request;
use rex_sql;
use rex_user;
use Sprog\Compat\Wildcard;

/**
 * Baut die clang-spezifischen Subpages im Backend-Page-Tree.
 *
 * Hängt auf PAGES_PREPARED — vor dem Render, nachdem package.yml verarbeitet
 * wurde. Die package.yml-Struktur legt nur die Root-Pages (`sprog/wildcard`,
 * `sprog/abbreviation`, …) an; pro rex_clang muss dynamisch eine Subpage
 * eingehängt werden, damit der User die Sprache wechseln kann ohne die Page
 * neu zu laden. clang-Permissions filtern Subpages weg, auf die der User
 * keinen Zugriff hat. Für Wildcards filtert clang_base zusätzlich Sprachen
 * weg, die auf eine andere Basis-Sprache zeigen.
 *
 * Bisher als 90-LOC-Closure inline in boot.php; hier in kleinere, einzeln
 * testbare static-Methoden zerlegt.
 */
final class PageTreeBuilder
{
    public static function publish(rex_addon $addon): void
    {
        $user = rex::getUser();
        if (null === $user) {
            return;
        }

        self::handleSettingsUpdate($user);
        self::buildAbbreviationSubpages($user);
        self::buildWildcardSubpages($user, $addon);
    }

    /**
     * Settings-Page schreibt zwei Config-Keys, bevor das Form HTML rendert.
     * Bewusst hier in PAGES_PREPARED — vor dem eigentlichen Page-Render, damit
     * die geänderten Werte im selben Request schon den Page-Tree beeinflussen
     * (z.B. wildcard_clang_switch toggelt das Subpage-Layout).
     */
    private static function handleSettingsUpdate(rex_user $user): void
    {
        if (!$user->isAdmin()) {
            return;
        }
        if ('sprog/settings' !== rex_be_controller::getCurrentPage()) {
            return;
        }
        if ('update' !== rex_request('func', 'string')) {
            return;
        }

        rex_config::set('sprog', 'wildcard_clang_switch', rex_request('clang_switch', 'bool'));
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

        $currentClangId = (int) str_replace('clang', '', rex_be_controller::getCurrentPagePart(3, ''));

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

    private static function buildWildcardSubpages(rex_user $user, rex_addon $addon): void
    {
        if (!($user->isAdmin() || $user->hasPerm('sprog[wildcard]'))) {
            return;
        }

        $page = rex_be_controller::getPageObject('sprog/wildcard');
        if (null === $page) {
            return;
        }

        if (!Wildcard::isClangSwitchMode()) {
            $page->setSubPath(rex_path::addon('sprog', 'pages/wildcard.clang_all.php'));
            return;
        }

        $hrefParams = self::collectWildcardHrefParams();
        $pidItems   = self::collectWildcardPidItems();

        $currentClangId = (int) str_replace('clang', '', rex_be_controller::getCurrentPagePart(3, ''));
        $page->setSubPath(rex_path::addon('sprog', 'pages/wildcard.clang_switch.php'));

        // Alle Sprachen, die eine andere clang_base haben, aus der Navigation
        // verstecken — der User würde sonst Untermenüs für nicht-eigenständige
        // Sprachen sehen.
        $clangs = rex_clang::getAll();
        /** @var array<int, int> $clangBase */
        $clangBase = $addon->getConfig('clang_base') ?: [];
        foreach ($clangs as $clang) {
            $id = $clang->getId();
            if (isset($clangBase[$id]) && $clangBase[$id] !== $id) {
                unset($clangs[$id]);
            }
        }

        foreach ($clangs as $id => $clang) {
            if (!$user->getComplexPerm('clang')->hasPerm($id)) {
                continue;
            }
            if (isset($pidItems[$id])) {
                $hrefParams['pid'] = $pidItems[$id];
            }
            $bePage = new rex_be_page('clang' . $id, $clang->getName());
            $bePage->setHref(\rex_url::backendPage('sprog/wildcard/clang' . $id, $hrefParams));
            $bePage->setSubPath(rex_path::addon('sprog', 'pages/wildcard.clang_switch.php'));
            $bePage->setIsActive($id === $currentClangId);
            $page->addSubpage($bePage);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function collectWildcardHrefParams(): array
    {
        $params = [];
        $searchTerm = (string) rex_request('search-term', 'string', '');
        if ('' !== $searchTerm) {
            $params['search-term'] = $searchTerm;
        }
        if ('edit' === rex_request('func', 'string') && 0 <= rex_request('pid', 'int', 0)) {
            $params['pid']  = rex_request('pid', 'int', 0);
            $params['func'] = 'edit';
        }
        return $params;
    }

    /**
     * Lädt für den aktuell editierten Wildcard die pid pro clang_id, damit
     * der Sprachwechsler-Link auf die korrekte Sprach-Variante derselben
     * Wildcard-ID zeigt — nicht auf die per-Sprache-pid des aktuellen Sets.
     *
     * @return array<int, int>
     */
    private static function collectWildcardPidItems(): array
    {
        if ('edit' !== rex_request('func', 'string') || 0 > rex_request('pid', 'int', 0)) {
            return [];
        }

        $pid = rex_request('pid', 'int', 0);
        $sql = rex_sql::factory();
        $idRows = $sql->getArray(
            'SELECT id FROM ' . rex::getTable('sprog_wildcard') . ' WHERE pid = :pid LIMIT 1',
            ['pid' => $pid],
        );
        if (!isset($idRows[0]['id'])) {
            return [];
        }

        $clangRows = $sql->getArray(
            'SELECT pid, clang_id FROM ' . rex::getTable('sprog_wildcard') . ' WHERE id = :id',
            ['id' => $idRows[0]['id']],
        );

        $items = [];
        foreach ($clangRows as $row) {
            $items[(int) $row['clang_id']] = (int) $row['pid'];
        }
        return $items;
    }
}
