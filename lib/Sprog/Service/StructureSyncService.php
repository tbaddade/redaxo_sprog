<?php

/**
 * This file is part of the Sprog package.
 *
 * @author (c) Thomas Blum <thomas@addoff.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Sprog\Service;

use Exception;
use rex;
use rex_api_exception;
use rex_article_cache;
use rex_sql;
use rex_sql_exception;

use function count;
use function in_array;

/**
 * Hält Struktur-Eigenschaften über die REDAXO-Sprachen synchron bzw. gleicht
 * Kategorie- und Startartikelname innerhalb einer Sprache ab.
 *
 * Zwei Achsen (siehe Konfiguration „Synchronisierung"):
 * - **Zwischen den Sprachen**: Status, Template und ausgewählte MetaInfo-Felder
 *   werden von der bearbeiteten Sprache auf die übrigen `rex_clang` gespiegelt
 *   (`clang_id != :clang`).
 * - **Innerhalb einer Sprache**: Kategoriename (`catname` des Startartikels) und
 *   Startartikelname (`name`) folgen einander (`clang_id = :clang`).
 *
 * Aufgerufen aus den Struktur-Extension-Points (siehe {@see \Sprog\Extension})
 * und der Copy-Funktion ({@see \Sprog\Copy\StructureMetadata}).
 */
final class StructureSyncService
{
    /**
     * Status auf alle anderen Sprachen derselben id spiegeln.
     *
     * @throws rex_api_exception
     */
    public static function syncStatusAcrossLanguages(int $id, int $clangId, int $status): void
    {
        try {
            rex_sql::factory()
                ->setTable(rex::getTable('article'))
                ->setWhere('id = :id AND clang_id != :clang', ['id' => $id, 'clang' => $clangId])
                ->setValue('status', $status)
                ->addGlobalUpdateFields()
                ->update();

            rex_article_cache::delete($id);
        } catch (rex_sql_exception $e) {
            throw new rex_api_exception($e->getMessage(), $e);
        }
    }

    /**
     * Template auf alle anderen Sprachen derselben id spiegeln.
     *
     * @throws rex_api_exception
     */
    public static function syncTemplateAcrossLanguages(int $id, int $clangId, int $templateId): void
    {
        if ($templateId <= 0) {
            return;
        }

        try {
            rex_sql::factory()
                ->setTable(rex::getTable('article'))
                ->setWhere('id = :id AND clang_id != :clang', ['id' => $id, 'clang' => $clangId])
                ->setValue('template_id', $templateId)
                ->addGlobalUpdateFields()
                ->update();

            rex_article_cache::delete($id);
        } catch (rex_sql_exception $e) {
            throw new rex_api_exception($e->getMessage(), $e);
        }
    }

    /**
     * Ausgewählte MetaInfo-Felder der bearbeiteten Sprache auf die übrigen
     * Sprachen spiegeln. `$toClangId > 0` schränkt auf genau eine Zielsprache
     * ein (genutzt von der Copy-Funktion), sonst gehen die Werte an alle
     * anderen Sprachen (`clang_id != :clang`).
     *
     * @param list<string> $fields zu synchronisierende Spaltennamen (art_… / cat_…)
     *
     * @throws rex_api_exception
     */
    public static function syncMetainfoAcrossLanguages(int $id, int $clangId, array $fields, int $toClangId = 0): void
    {
        $table = rex::getTable('article');

        // Nur real existierende Spalten übernehmen (Config kann veraltete
        // MetaInfo-Feldnamen enthalten).
        $columns = rex_sql::factory()->setQuery('SELECT * FROM ' . $table . ' LIMIT 1')->getFieldnames();
        $fields = array_values(array_filter($fields, static fn (string $field): bool => in_array($field, $columns, true)));

        if (0 === count($fields)) {
            return;
        }

        $source = rex_sql::factory()
            ->setTable($table)
            ->setWhere('id = :id AND clang_id = :clang', ['id' => $id, 'clang' => $clangId])
            ->select(implode(',', $fields))
            ->getArray();

        if (1 !== count($source)) {
            return;
        }

        try {
            rex_sql::factory()
                ->setTable($table)
                ->setWhere('id = :id AND clang_id ' . ($toClangId > 0 ? '=' : '!=') . ' :clang', ['id' => $id, 'clang' => $toClangId > 0 ? $toClangId : $clangId])
                ->setValues($source[0])
                ->addGlobalUpdateFields()
                ->update();

            rex_article_cache::delete($id);
        } catch (rex_sql_exception $e) {
            throw new rex_api_exception($e->getMessage(), $e);
        }
    }

    /**
     * Startartikelname → Kategoriename (`catname`), innerhalb derselben Sprache.
     * Wirkt ausschließlich auf den Startartikel (`startarticle = 1`) — nur er
     * trägt den Kategorienamen; wird ein normaler Artikel umbenannt, passiert
     * nichts.
     *
     * @throws rex_api_exception
     */
    public static function syncArticleNameToCategoryName(int $id, int $clangId, string $name): void
    {
        if ('' === $name) {
            return;
        }

        try {
            rex_sql::factory()
                ->setTable(rex::getTable('article'))
                ->setWhere('id = :id AND clang_id = :clang AND startarticle = 1', ['id' => $id, 'clang' => $clangId])
                ->setValue('catname', $name)
                ->addGlobalUpdateFields()
                ->update();

            rex_article_cache::delete($id, $clangId);
        } catch (rex_sql_exception $e) {
            throw new rex_api_exception($e->getMessage(), $e);
        }
    }

    /**
     * Kategoriename → Startartikelname (`name`), innerhalb derselben Sprache.
     *
     * @throws rex_api_exception
     */
    public static function syncCategoryNameToArticleName(int $id, int $clangId, string $categoryName): void
    {
        if ('' === $categoryName) {
            return;
        }

        try {
            rex_sql::factory()
                ->setTable(rex::getTable('article'))
                ->setWhere('id = :id AND clang_id = :clang AND startarticle = 1', ['id' => $id, 'clang' => $clangId])
                ->setValue('name', $categoryName)
                ->addGlobalUpdateFields()
                ->update();

            rex_article_cache::delete($id, $clangId);
        } catch (rex_sql_exception $e) {
            throw new rex_api_exception($e->getMessage(), $e);
        }
    }
}
