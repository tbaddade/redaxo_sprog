<?php

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
use Sprog\Compat\Sync;
use Sprog\Compat\Wildcard;

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
     * @param rex_extension_point<mixed> $ep
     */
    public static function articleUpdated(rex_extension_point $ep): void
    {
        $addon = rex_addon::get('sprog');

        if ($addon->getConfig('sync_structure_article_name_to_category_name')) {
            Sync::articleNameToCategoryName($ep->getParams());
        }

        if ($addon->getConfig('sync_structure_status')) {
            Sync::articleStatus($ep->getParams());
        }

        if ($addon->getConfig('sync_structure_template')) {
            Sync::articleTemplate($ep->getParams());
        }
    }

    /**
     * @param rex_extension_point<mixed> $ep
     */
    public static function articleMetadataUpdated(rex_extension_point $ep): void
    {
        $addon = rex_addon::get('sprog');
        $fields = $addon->getConfig('sync_metainfo_art', []);
        if (count($fields)) {
            Sync::articleMetainfo($ep->getParams(), $fields);
        }
    }

    /**
     * @param rex_extension_point<mixed> $ep
     */
    public static function categoryUpdated(rex_extension_point $ep): void
    {
        $addon = rex_addon::get('sprog');

        if ($addon->getConfig('sync_structure_category_name_to_article_name')) {
            Sync::categoryNameToArticleName($ep->getParams());
        }

        $fields = $addon->getConfig('sync_metainfo_cat', []);
        if (count($fields)) {
            Sync::categoryMetainfo($ep->getParams(), $fields);
        }

        if ($addon->getConfig('sync_structure_status')) {
            Sync::articleStatus($ep->getParams());
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

        self::clangAddedV1($clangId);
        self::clangAddedV2($clangId);
    }

    /**
     * @param rex_extension_point<mixed> $ep
     */
    public static function clangDeleted(rex_extension_point $ep): void
    {
        $clangId = $ep->getParam('clang')->getId();

        $deleteLang = rex_sql::factory();
        $deleteLang->setQuery('DELETE FROM ' . rex::getTable('sprog_wildcard') . ' WHERE clang_id=?', [$clangId]);

        self::clangDeletedV2($clangId);
    }

    /**
     * v1-Verhalten beibehalten: Wildcard-Rows der Start-Sprache für die
     * neue clang replizieren. Bestandsinstallationen, die v2 (noch) nicht
     * benutzen, sehen kein Verhaltens-Delta.
     */
    private static function clangAddedV1(int $clangId): void
    {
        $firstLang = rex_sql::factory();
        $firstLang->setQuery('SELECT * FROM ' . rex::getTable('sprog_wildcard') . ' WHERE clang_id=?', [rex_clang::getStartId()]);
        $fields = $firstLang->getFieldnames();

        $newLang = rex_sql::factory();
        $newLang->setDebug(false);
        foreach ($firstLang as $firstLangEntry) {
            $newLang->setTable(rex::getTable('sprog_wildcard'));

            foreach ($fields as $key => $value) {
                if ('pid' == $value) {
                    continue;
                }
                if ('clang_id' == $value) {
                    $newLang->setValue('clang_id', $clangId);
                } else {
                    $newLang->setValue($value, $firstLangEntry->getValue($value));
                }
            }

            $newLang->insert();
        }
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
     * @param rex_extension_point<array<string, mixed>> $ep
     */
    public static function wildcardFormControlElement(rex_extension_point $ep): void
    {
        $subject = $ep->getSubject();
        $subject['delete'] = '';
        $ep->setSubject($subject);
    }
}
