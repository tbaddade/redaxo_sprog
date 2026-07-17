<?php

declare(strict_types=1);

/**
 * This file is part of the Sprog package.
 *
 * @author (c) Thomas Blum <thomas@addoff.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sprog;

use rex;
use rex_addon;
use rex_clang;
use rex_extension_point;
use rex_sql;
use rex_sql_exception;
use Sprog\Compat\Abbreviation;
use Sprog\Compat\Foreignword;
use Sprog\Compat\Wildcard;
use Sprog\Service\StructureSyncService;
use Sprog\Support\StructureClangGuard;
use Sprog\View\LangCompare;

use function array_values;
use function count;

class Extension
{
    /**
     * @param rex_extension_point<string> $ep
     */
    public static function replaceAbbreviations(rex_extension_point $ep): void
    {
        $ep->setSubject(Abbreviation::parse($ep->getSubject(), null));
    }

    /**
     * @param rex_extension_point<string> $ep
     */
    public static function replaceWildcards(rex_extension_point $ep): void
    {
        $ep->setSubject(Wildcard::parse($ep->getSubject(), null));
    }

    /**
     * @param rex_extension_point<string> $ep
     */
    public static function replaceForeignwords(rex_extension_point $ep): void
    {
        $ep->setSubject(Foreignword::parse($ep->getSubject(), null));
    }

    /**
     * Statuswechsel (ART_STATUS / CAT_STATUS) auf die anderen Sprachen spiegeln.
     * Bewusst getrennt von articleUpdated/categoryUpdated: nur ein echter
     * Statuswechsel soll den Status angleichen, nicht jeder Namens-/Template-Edit.
     *
     * @param rex_extension_point<mixed> $ep
     */
    public static function statusUpdated(rex_extension_point $ep): void
    {
        if (!rex_addon::get('sprog')->getConfig('sync_structure_status')) {
            return;
        }

        $params = $ep->getParams();
        StructureSyncService::syncStatusAcrossLanguages((int) $params['id'], (int) $params['clang'], (int) $params['status']);
    }

    /**
     * Artikel-Update (ART_UPDATED): Startartikelname → Kategoriename (innerhalb
     * der Sprache) und Template → andere Sprachen.
     *
     * @param rex_extension_point<mixed> $ep
     */
    public static function articleUpdated(rex_extension_point $ep): void
    {
        $addon = rex_addon::get('sprog');
        $params = $ep->getParams();

        if ($addon->getConfig('sync_structure_article_name_to_category_name')) {
            StructureSyncService::syncArticleNameToCategoryName((int) $params['id'], (int) $params['clang'], (string) ($params['name'] ?? ''));
        }

        if ($addon->getConfig('sync_structure_template')) {
            StructureSyncService::syncTemplateAcrossLanguages((int) $params['id'], (int) $params['clang'], (int) ($params['template_id'] ?? 0));
        }
    }

    /**
     * Artikel-MetaInfo (ART_META_UPDATED): ausgewählte Felder → andere Sprachen.
     *
     * @param rex_extension_point<mixed> $ep
     */
    public static function articleMetadataUpdated(rex_extension_point $ep): void
    {
        $fields = (array) rex_addon::get('sprog')->getConfig('sync_metainfo_art', []);
        if (0 === count($fields)) {
            return;
        }

        $params = $ep->getParams();
        StructureSyncService::syncMetainfoAcrossLanguages((int) $params['id'], (int) $params['clang'], array_values($fields));
    }

    /**
     * Kategorie-Update (CAT_UPDATED, LATE): Kategoriename → Startartikelname
     * (innerhalb der Sprache) und Kategorie-MetaInfo → andere Sprachen. Der Core
     * feuert kein CAT_META_UPDATED, daher läuft die MetaInfo-Sync hier — LATE,
     * damit der MetaInfo-Handler seine Felder zuerst persistiert.
     *
     * @param rex_extension_point<mixed> $ep
     */
    public static function categoryUpdated(rex_extension_point $ep): void
    {
        $addon = rex_addon::get('sprog');
        $params = $ep->getParams();

        if ($addon->getConfig('sync_structure_category_name_to_article_name')) {
            StructureSyncService::syncCategoryNameToArticleName((int) $params['id'], (int) $params['clang'], (string) ($params['name'] ?? ''));
        }

        $fields = (array) $addon->getConfig('sync_metainfo_cat', []);
        if (count($fields) > 0) {
            StructureSyncService::syncMetainfoAcrossLanguages((int) $params['id'], (int) $params['clang'], array_values($fields));
        }
    }

    // mediaUpdated()-EP-Handler wurde entfernt — Medienpool ist nicht
    // mehrsprachig, die korrespondierenden MEDIA_*-Registrierungen sind in
    // boot.php auskommentiert.

    /**
     * @param rex_extension_point<mixed> $ep
     */
    public static function clangAdded(rex_extension_point $ep): void
    {
        $clangId = $ep->getParam('clang')->getId();

        self::clangAddedV2($clangId);
    }

    /**
     * @param rex_extension_point<mixed> $ep
     */
    public static function clangDeleted(rex_extension_point $ep): void
    {
        $clangId = $ep->getParam('clang')->getId();

        self::clangDeletedV2($clangId);
    }

    /**
     * v2-Sync: pro existierender Unit eine missing-Translation für die neue
     * Sprache anlegen. So bleibt die Inbox/Coverage konsistent — die neue
     * clang taucht überall sofort als „fehlt" auf, statt dass der
     * WildcardLookupService still auf den v1-Pfad zurückfällt und divergiert.
     *
     * INSERT IGNORE schützt vor Duplikaten am UNIQUE-Index (unit_id, clang_id),
     * falls Migration und CLANG_ADDED in derselben Request-Reihenfolge laufen.
     *
     * Die v2-Tabellen können (noch) fehlen, wenn install.php noch nicht
     * gelaufen ist — Try/Catch fängt das ab und das Addon-Verhalten reduziert
     * sich auf v1.
     */
    private static function clangAddedV2(int $clangId): void
    {
        try {
            rex_sql::factory()->setQuery(
                'INSERT IGNORE INTO ' . rex::getTable('sprog_translation') . '
                    (unit_id, clang_id, value, value_hash, source_hash_at_translation,
                     status, revision, createdate, createuser, updatedate, updateuser)
                 SELECT u.id, :clang_id, \'\', NULL, NULL, \'missing\', 0,
                        NOW(), \'system\', NOW(), \'system\'
                 FROM ' . rex::getTable('sprog_unit') . ' u',
                ['clang_id' => $clangId],
            );
        } catch (rex_sql_exception) {
            // v2-Schema noch nicht installiert — kein Sync nötig.
        }
    }

    /**
     * v2-Cleanup: alle Translations der gelöschten Sprache entfernen. Spiegelt
     * das v1-Verhalten (Wildcard-Rows der clang löschen) für die v2-Tabellen.
     */
    private static function clangDeletedV2(int $clangId): void
    {
        try {
            rex_sql::factory()->setQuery(
                'DELETE FROM ' . rex::getTable('sprog_translation') . ' WHERE clang_id = :clang_id',
                ['clang_id' => $clangId],
            );
        } catch (rex_sql_exception) {
            // v2-Schema noch nicht installiert — keine v2-Daten vorhanden.
        }
    }

    /**
     * Toolbar des Artikel-Sprachvergleichs in die Content-Maske einhängen
     * (STRUCTURE_CONTENT_HEADER). Subject ist der bisher aufgebaute HTML-String.
     *
     * @param rex_extension_point<string> $ep
     */
    public static function langCompareHeader(rex_extension_point $ep): void
    {
        $html = LangCompare::renderBar(
            (int) $ep->getParam('article_id'),
            (int) $ep->getParam('clang'),
            (int) $ep->getParam('ctype'),
            (int) $ep->getParam('article_revision'),
        );

        if ('' !== $html) {
            $ep->setSubject($ep->getSubject() . $html);
        }
    }

    /**
     * Leeren Panel-Host unter die Slice-Liste hängen
     * (STRUCTURE_CONTENT_AFTER_SLICES); Befüllung erfolgt per JS.
     *
     * @param rex_extension_point<string> $ep
     */
    public static function langCompareContainer(rex_extension_point $ep): void
    {
        $ep->setSubject($ep->getSubject() . LangCompare::renderContainer());
    }

    /**
     * Erlaubte Sprachen der aktuellen Kategorie/des Artikels als verstecktes
     * data-Element in die Struktur einhängen (Liste: PAGE_STRUCTURE_HEADER,
     * Content-Maske: STRUCTURE_CONTENT_HEADER). assets/js/sprog.structureclang.js
     * blendet daraufhin die nicht bedienten Sprach-Buttons aus. Kontext-ID ist der
     * Artikel (Content-Maske) bzw. die geöffnete Kategorie (Liste).
     *
     * @param rex_extension_point<string> $ep
     */
    public static function structureClangData(rex_extension_point $ep): void
    {
        if (!StructureClangGuard::isEnabled()) {
            return;
        }

        $contextId = (int) $ep->getParam('article_id');
        if ($contextId <= 0) {
            $contextId = (int) $ep->getParam('category_id');
        }
        $clang = (int) ($ep->getParam('clang') ?? rex_clang::getCurrentId());
        if ($clang <= 0) {
            $clang = rex_clang::getCurrentId();
        }

        $span = StructureClangGuard::dataSpan($contextId, $clang);
        if ('' !== $span) {
            $ep->setSubject($ep->getSubject() . $span);
        }
    }

    /**
     * @param rex_extension_point<array<string, mixed>> $ep
     */
    public static function wildcardFormControlElement(rex_extension_point $ep): void
    {
        $subject = $ep->getSubject();
        $subject['delete'] = '';
        $ep->setSubject($subject);
    }
}
