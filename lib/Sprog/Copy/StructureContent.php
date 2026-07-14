<?php

declare(strict_types=1);

namespace Sprog\Copy;

use rex;
use rex_addon;
use rex_article_cache;
use rex_article_content;
use rex_clang;
use rex_extension;
use rex_extension_point;
use rex_logger;
use rex_sql;
use rex_sql_util;
use Throwable;

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

        $chunkedItems = self::chunk($items, (int) rex_addon::get('sprog')->getConfig('chunk_size_articles'));
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

                // Cache der Zielsprache neu aufbauen — best effort. copyContent()
                // hat den Content-Cache bereits invalidiert (deleteContent), das
                // Rendern hier ist nur Warmup. Ein nicht renderbarer Artikel darf
                // den Kopiervorgang NICHT abbrechen: z.B. wenn die Zielsprache
                // nicht in yrewrite gemountet ist, wirft das Rendern „getUrl() on
                // null". Die Slices sind zu diesem Zeitpunkt bereits kopiert.
                try {
                    // generate content
                    $article = new rex_article_content($item[0], $params['clangTo']);
                    $article->getArticle();

                    // generate meta
                    rex_article_cache::generateMeta($item[0], $params['clangTo']);

                    // generate lists
                    rex_article_cache::generateLists($item[0]);
                } catch (Throwable $e) {
                    rex_logger::logException($e);
                }
            }
        }
        return $items;
    }

    /**
     * Kopiert die Inhalte eines Artikels in einen anderen Artikel.
     *
     * @return bool TRUE bei Erfolg, sonst FALSE
     */
    public static function copyContent(int $fromId, int $toId, int $fromClang = 1, int $toClang = 1, int $revision = 0): bool
    {
        if ($fromId == $toId && $fromClang == $toClang) {
            return false;
        }

        $gc = rex_sql::factory();
        $gc->setQuery(
            'SELECT * FROM ' . rex::getTable('article_slice') . ' WHERE `article_id` = :from_id AND `clang_id` = :from_clang AND `revision` = :revision',
            ['from_id' => $fromId, 'from_clang' => $fromClang, 'revision' => $revision],
        );

        if ($gc->getRows() > 0) {
            rex_extension::registerPoint(new rex_extension_point('ART_SLICES_COPY', '', [
                'article_id' => $toId,
                'clang_id' => $toClang,
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
                ['to_id' => $toId, 'to_clang' => $toClang, 'revision' => $revision],
            );
            $maxPriority = (1 == $max->getRows()) ? (int) $max->getValue('max') : 0;

            $user = rex::isBackend() ? null : 'frontend';

            foreach ($gc as $slice) {
                foreach ($cols as $col) {
                    $colname = (string) $col->getValue('Field');
                    if ('clang_id' == $colname) {
                        $value = $toClang;
                    } elseif ('article_id' == $colname) {
                        $value = $toId;
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
                    'article_id=' . $toId . ' AND clang_id=' . $toClang . ' AND ctype_id=' . $ctype . ' AND revision=' . $revision,
                    'priority, updatedate',
                );
            }

            rex_article_cache::deleteContent($toId, $toClang);
            return true;
        }

        return false;
    }

    /**
     * Löscht die Slices der Zielsprache, bevor kopiert wird („vorher löschen").
     * Ohne Start-Artikel: die gesamte Zielsprache; mit Start-Artikel: nur der
     * Teilbaum ab diesem Artikel. Exakt das Verhalten des früheren Popups.
     */
    public static function purgeTargetSlices(int $clangTo, ?int $startingArticleId = null): void
    {
        $sql = rex_sql::factory();
        $tableSlice = rex::getTable('article_slice');

        if (null === $startingArticleId) {
            $sql->setQuery('DELETE FROM ' . $tableSlice . ' WHERE `clang_id` = :clang', ['clang' => $clangTo]);
            return;
        }

        $tableArticle = rex::getTable('article');
        $ids = $sql->getArray(
            'WITH RECURSIVE articles(id, parent_id) AS (
                SELECT a.id, a.parent_id FROM ' . $tableArticle . ' a WHERE a.id = :article_id AND a.clang_id = :clang
                UNION ALL
                SELECT a.id, a.parent_id FROM ' . $tableArticle . ' a INNER JOIN articles cte ON a.parent_id = cte.id WHERE a.clang_id = :clang
            ) SELECT id FROM articles',
            ['clang' => $clangTo, 'article_id' => $startingArticleId],
        );

        foreach ($ids as $row) {
            $sql->setQuery(
                'DELETE FROM ' . $tableSlice . ' WHERE `article_id` = :article AND `clang_id` = :clang',
                ['article' => (int) $row['id'], 'clang' => $clangTo],
            );
        }
    }
}
