<?php

namespace Sprog\Copy;

use Sprog\Sync;

class StructureMetadata extends Copy
{
    /**
     * Prepare all cache items.
     *
     * @return array
     */
    public static function prepareItems()
    {
        return [
            'articles' => self::getChunkedArray(),
        ];
    }

    /**
     * Get all pages being online.
     *
     * @return array
     */
    public static function getArticleIds()
    {
        $articles = [];
        if (\rex_addon::get('structure')->isAvailable()) {
            $sql = \rex_sql::factory();
            $items = $sql->getArray('SELECT `id` FROM '.\rex::getTable('article').' GROUP BY `id`');

            foreach ($items as $item) {
                $articles[] = $item['id'];
            }
        }
        return $articles;
    }

    /**
     * Get all pages and languages as chunked array including 'count' and 'items'.
     *
     * @return array
     */
    public static function getChunkedArray()
    {
        $articles = self::getArticleIds();

        $items = [];
        if (count($articles) > 0 && \rex_clang::count() > 0) {
            foreach ($articles as $article) {
                $items[] = [$article, \rex_clang::getStartId()];
            }
        }

        $chunkedItems = self::chunk($items, \rex_addon::get('sprog')->getConfig('chunkSizeArticles'));
        return ['count' => count($items), 'params' => rex_request('params', 'array', 0), 'items' => $chunkedItems];
    }

    /**
     * @param array $items
     * @param array $params
     *
     * @return array
     */
    public static function fire(array $items, array $params)
    {
        if (\rex_addon::get('structure')->isAvailable() && $params['clangFrom'] != $params['clangTo']) {
            foreach ($items as $item) {
                $syncParams = [
                    'id' => $item[0],
                    'clang' => $params['clangFrom'],
                ];
                $syncFields = explode(',', $params['fields']);
                Sync::articleMetainfo($syncParams, $syncFields, $params['clangTo']);

                // generate content
                $article = new \rex_article_content($item[0], $params['clangTo']);
                $content = $article->getArticle();

                // generate meta
                \rex_article_cache::generateMeta($item[0], $params['clangTo']);

                // generate lists
                \rex_article_cache::generateLists($item[0]);
            }
        }
        return $items;
    }
}
