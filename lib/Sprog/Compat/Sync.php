<?php

/**
 * This file is part of the Sprog package.
 *
 * @author (c) Thomas Blum <thomas@addoff.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sprog\Compat;

use rex;
use rex_api_exception;
use rex_article_cache;
use rex_sql;
use rex_sql_exception;

use function count;
use function in_array;

class Sync
{
    /**
     * @param array<string, mixed> $params Extension-Point-Params (id, clang, parent_id, name, …)
     */
    public static function articleNameToCategoryName(array $params): void
    {
        try {
            $id = $params['id'];
            $clangId = $params['clang'];
            $parentId = $params['parent_id'] ?? -1;
            $articleName = $params['name'] ?? '';

            if ('' != $articleName) {
                rex_sql::factory()
                    ->setTable(rex::getTable('article'))
                    ->setWhere('(id = :id OR (parent_id = :parent_id AND startarticle = 0)) AND clang_id = :clang', ['id' => $id, 'parent_id' => $parentId, 'clang' => $clangId])
                    ->setValue('catname', $articleName)
                    ->addGlobalUpdateFields()
                    ->update();

                rex_article_cache::delete($id, $clangId);
            }
        } catch (rex_sql_exception $e) {
            throw new rex_api_exception($e);
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function categoryNameToArticleName(array $params): void
    {
        try {
            $id = $params['id'];
            $clangId = $params['clang'];
            $categoryName = $params['data']['catname'] ?? '';

            if ('' != $categoryName) {
                rex_sql::factory()
                    ->setTable(rex::getTable('article'))
                    ->setWhere('id = :id AND clang_id = :clang', ['id' => $id, 'clang' => $clangId])
                    ->setValue('name', $categoryName)
                    ->addGlobalUpdateFields()
                    ->update();

                rex_article_cache::delete($id, $clangId);
            }
        } catch (rex_sql_exception $e) {
            throw new rex_api_exception($e);
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function articleStatus(array $params): void
    {
        try {
            $id = $params['id'];
            $clangId = $params['clang'];
            $status = $params['status'];

            // ----- Update Article Status
            rex_sql::factory()
                ->setTable(rex::getTable('article'))
                ->setWhere('id = :id AND clang_id != :clang', ['id' => $id, 'clang' => $clangId])
                ->setValue('status', $status)
                ->addGlobalUpdateFields()
                ->update();

            rex_article_cache::delete($id);
        } catch (rex_sql_exception $e) {
            throw new rex_api_exception($e);
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function articleTemplate(array $params): void
    {
        try {
            $id = $params['id'];
            $clangId = $params['clang'];
            $templateId = $params['template_id'] ?? 0;

            // ----- Update Template Id
            if ($templateId > 0) {
                rex_sql::factory()
                    ->setTable(rex::getTable('article'))
                    ->setWhere('id = :id AND clang_id != :clang', ['id' => $id, 'clang' => $clangId])
                    ->setValue('template_id', $templateId)
                    ->addGlobalUpdateFields()
                    ->update();

                rex_article_cache::delete($id);
            }
        } catch (rex_sql_exception $e) {
            throw new rex_api_exception($e);
        }
    }

    /**
     * @param array<string, mixed> $params
     * @param list<string>         $fields zu synchronisierende Spaltennamen
     */
    public static function articleMetainfo(array $params, array $fields, int $toClangId = 0): void
    {
        // Check whether field exists in table
        $sql = rex_sql::factory()->setQuery('SELECT * FROM ' . rex::getTable('article') . ' LIMIT 1');
        $fieldNames = $sql->getFieldnames();
        foreach ($fields as $index => $field) {
            if (!in_array($field, $fieldNames)) {
                unset($fields[$index]);
            }
        }

        if (count($fields) < 1) {
            return;
        }

        $id = $params['id'];
        $clangId = $params['clang'];
        $saveFields = rex_sql::factory()
            ->setTable(rex::getTable('article'))
            ->setWhere('id = :id AND clang_id = :clang', ['id' => $id, 'clang' => $clangId])
            ->select(implode(',', $fields))
            ->getArray();

        if (1 == count($saveFields)) {
            $saveFields = $saveFields[0];
            try {
                // ----- Update Category Metainfo
                rex_sql::factory()
                    ->setTable(rex::getTable('article'))
                    ->setWhere('id = :id AND clang_id ' . ($toClangId > 0 ? '=' : '!=') . ' :clang', ['id' => $id, 'clang' => ($toClangId > 0 ? $toClangId : $clangId)])
                    ->setValues($saveFields)
                    ->addGlobalUpdateFields()
                    ->update();

                rex_article_cache::delete($id);
            } catch (rex_sql_exception $e) {
                throw new rex_api_exception($e);
            }
        }
    }

    /**
     * @param array<string, mixed> $params
     * @param list<string>         $fields
     */
    public static function categoryMetainfo(array $params, array $fields): void
    {
        self::articleMetainfo($params, $fields);
    }
}
