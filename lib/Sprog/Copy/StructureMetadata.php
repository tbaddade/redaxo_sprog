<?php

declare(strict_types=1);

namespace Sprog\Copy;

use rex;
use rex_addon;
use rex_article_cache;
use rex_article_content;
use rex_clang;
use rex_sql;
use Sprog\Compat\Sync;

use function count;

class StructureMetadata extends Copy
{
    /**
     * Prepare all cache items.
     *
     * @return array{articles: array{count: int, params: mixed, items: list<list<array{0: int, 1: int}>>}}
     */
    public static function prepareItems(): array
    {
        return [
            'articles' => self::getChunkedArray(),
        ];
    }

    /**
     * Get all pages being online.
     *
     * @return list<int>
     */
    public static function getArticleIds(): array
    {
        $articles = [];
        if (rex_addon::get('structure')->isAvailable()) {
            $sql = rex_sql::factory();
            $items = $sql->getArray('SELECT `id` FROM ' . rex::getTable('article') . ' GROUP BY `id`');

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
    public static function getChunkedArray(): array
    {
        $articles = self::getArticleIds();

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
     * @param list<array{0: int, 1: int}>                            $items
     * @param array{clangFrom: int, clangTo: int, fields: string}    $params
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function fire(array $items, array $params): array
    {
        if (rex_addon::get('structure')->isAvailable() && $params['clangFrom'] != $params['clangTo']) {
            foreach ($items as $item) {
                $syncParams = [
                    'id' => $item[0],
                    'clang' => $params['clangFrom'],
                ];
                $syncFields = explode(',', $params['fields']);
                Sync::articleMetainfo($syncParams, $syncFields, $params['clangTo']);

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
}
