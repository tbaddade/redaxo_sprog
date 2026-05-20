<?php

namespace Sprog\Copy;

use rex;
use rex_addon;
use rex_article_cache;
use rex_article_content;
use rex_clang;
use rex_extension;
use rex_extension_point;
use rex_sql;
use rex_sql_util;

use function count;

class StructureContent extends Copy
{
    /**
     * Prepare all cache items.
     *
     * @return array{articles: array{count: int, params: mixed, items: list<list<array{0: int, 1: int}>>}}
     */
    public static function prepareItems(?int $startingArticleId = null): array
    {
        return [
            'articles' => self::getChunkedArray($startingArticleId),
        ];
    }

    /**
     * Get all pages being online.
     *
     * @return list<int>
     */
    public static function getArticleIds(?int $startingArticleId = null): array
    {
        $articles = [];
        if (rex_addon::get('structure')->isAvailable()) {
            $sql = rex_sql::factory();

            if (null == $startingArticleId) {
                $items = $sql->getArray('SELECT `id` FROM ' . rex::getTable('article') . ' GROUP BY `id`');
            } else {
                $tableArticle = rex::getTable('article');

                $clang_id = rex_clang::getStartId();
                $query = <<<EOM
                                        WITH RECURSIVE articles(id, name, parent_id) as (
                                            SELECT a.id, a.name, a.parent_id
                                                FROM $tableArticle a
                                                WHERE a.id = :article_id AND a.clang_id = :clang_id
                                            UNION ALL
                                            SELECT a.id, a.name, a.parent_id
                                                FROM $tableArticle a
                                                INNER JOIN articles cte
                                                    ON a.parent_id = cte.id
                                                WHERE a.clang_id = :clang_id
                                        )
                                        SELECT id FROM articles        	
                    EOM;

                $items = $sql->getArray($query, [
                    'clang_id' => $clang_id,
                    'article_id' => $startingArticleId,
                ]);
            }

            foreach ($items as $item) {
                $articles[] = (int) $item['id'];
            }
        }
        return $articles;
    }

    /**
     * Get all pages and languages as chunked array including 'count' and 'items'.
     *
     * @return array{count: int, params: mixed, items: list<list<array{0: int, 1: int}>>}
     */
    public static function getChunkedArray(?int $startingArticleId = null): array
    {
        $articles = self::getArticleIds($startingArticleId);

        $items = [];
        if (count($articles) > 0 && rex_clang::count() > 0) {
            foreach ($articles as $article) {
                $items[] = [$article, rex_clang::getStartId()];
            }
        }

        $chunkedItems = self::chunk($items, (int) rex_addon::get('sprog')->getConfig('chunkSizeArticles'));
        return ['count' => count($items), 'params' => rex_request('params', 'array', 0), 'items' => $chunkedItems];
    }

    /**
     * @param list<array{0: int, 1: int}> $items     Tupel aus [article_id, source_clang_id]
     * @param array{clangFrom: int, clangTo: int}    $params
     *
     * @return list<array{0: int, 1: int}> die unveränderte Items-Liste (für Chunked-Progress)
     */
    public static function fire(array $items, array $params): array
    {
        if (rex_addon::get('structure')->isAvailable() && $params['clangFrom'] != $params['clangTo']) {
            foreach ($items as $item) {
                self::copyContent($item[0], $item[0], $params['clangFrom'], $params['clangTo']);

                // generate content
                $article = new rex_article_content($item[0], $params['clangTo']);
                $content = $article->getArticle();

                // generate meta
                rex_article_cache::generateMeta($item[0], $params['clangTo']);

                // generate lists
                rex_article_cache::generateLists($item[0]);
            }
        }
        return $items;
    }

    /**
     * Kopiert die Inhalte eines Artikels in einen anderen Artikel.
     *
     * @param int $from_id    ArtikelId des Artikels, aus dem kopiert werden (Quell ArtikelId)
     * @param int $to_id      ArtikelId des Artikel, in den kopiert werden sollen (Ziel ArtikelId)
     * @param int $from_clang ClangId des Artikels, aus dem kopiert werden soll (Quell ClangId)
     * @param int $to_clang   ClangId des Artikels, in den kopiert werden soll (Ziel ClangId)
     * @param int $revision
     *
     * @return bool TRUE bei Erfolg, sonst FALSE
     */
    public static function copyContent($from_id, $to_id, $from_clang = 1, $to_clang = 1, $revision = 0)
    {
        if ($from_id == $to_id && $from_clang == $to_clang) {
            return false;
        }

        $gc = rex_sql::factory();
        $gc->setQuery(
            'SELECT * FROM ' . rex::getTable('article_slice') . ' WHERE `article_id` = :from_id AND `clang_id` = :from_clang AND `revision` = :revision',
            ['from_id' => $from_id, 'from_clang' => $from_clang, 'revision' => $revision],
        );

        if ($gc->getRows() > 0) {
            rex_extension::registerPoint(new rex_extension_point('ART_SLICES_COPY', '', [
                'article_id' => $to_id,
                'clang_id' => $to_clang,
                'slice_revision' => $revision,
            ]));

            $ins = rex_sql::factory();
            // $ins->setDebug();
            $ctypes = [];

            $cols = rex_sql::factory();
            // $cols->setDebug();
            $cols->setQuery('SHOW COLUMNS FROM ' . rex::getTablePrefix() . 'article_slice');

            $max = rex_sql::factory();
            $max->setQuery(
                'SELECT MAX(`priority`) as max FROM ' . rex::getTable('article_slice') . ' WHERE `article_id` = :to_id AND `clang_id` = :to_clang AND `revision` = :revision',
                ['to_id' => $to_id, 'to_clang' => $to_clang, 'revision' => $revision],
            );
            $maxPriority = (1 == $max->getRows()) ? (int) $max->getValue('max') : 0;

            $user = rex::isBackend() ? null : 'frontend';

            foreach ($gc as $slice) {
                foreach ($cols as $col) {
                    $colname = (string) $col->getValue('Field');
                    if ('clang_id' == $colname) {
                        $value = $to_clang;
                    } elseif ('article_id' == $colname) {
                        $value = $to_id;
                    } elseif ('priority' == $colname) {
                        $value = $maxPriority + (int) $slice->getValue($colname);
                    } else {
                        $value = $slice->getValue($colname);
                    }

                    // collect all affected ctypes
                    if ('ctype_id' == $colname) {
                        $ctypeId = (int) $value;
                        $ctypes[$ctypeId] = $ctypeId;
                    }

                    if ('id' != $colname) {
                        $ins->setValue($colname, $value);
                    }
                }

                $ins->addGlobalUpdateFields($user);
                $ins->addGlobalCreateFields($user);
                $ins->setTable(rex::getTablePrefix() . 'article_slice');
                $ins->insert();
            }

            foreach ($ctypes as $ctype) {
                // reorg slices
                rex_sql_util::organizePriorities(
                    rex::getTable('article_slice'),
                    'priority',
                    'article_id=' . $to_id . ' AND clang_id=' . $to_clang . ' AND ctype_id=' . $ctype . ' AND revision=' . $revision,
                    'priority, updatedate',
                );
            }

            rex_article_cache::deleteContent($to_id, $to_clang);
            return true;
        }

        return false;
    }
}
