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

class Extension
{
    public static function replaceWildcards(\rex_extension_point $ep)
    {
        $ep->setSubject(Wildcard::parse($ep->getSubject(), null));
    }

    public static function articleUpdated(\rex_extension_point $ep)
    {
        $addon = \rex_addon::get('sprog');

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

    public static function articleMetadataUpdated(\rex_extension_point $ep)
    {
        $addon = \rex_addon::get('sprog');
        $fields = $addon->getConfig('sync_metainfo_art', []);
        if (count($fields)) {
            Sync::articleMetainfo($ep->getParams(), $fields);
        }
    }

    public static function categoryUpdated(\rex_extension_point $ep)
    {
        $addon = \rex_addon::get('sprog');

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

    /*
     * Medienpool ist noch nicht mehrsprachig
    public static function mediaUpdated(\rex_extension_point $ep)
    {
        $addon = \rex_addon::get('sprog');

        if (count($addon->getConfig('sync_metainfo_med'))) {
            Sync::mediaMetainfo($ep->getParams(), $addon->getConfig('sync_metainfo_med'));
        }
    }
    */

    public static function clangAdded(\rex_extension_point $ep)
    {
        $firstLang = \rex_sql::factory();
        $firstLang->setQuery('SELECT * FROM '.\rex::getTable('sprog_wildcard').' WHERE clang_id=?', [\rex_clang::getStartId()]);
        $fields = $firstLang->getFieldnames();

        $newLang = \rex_sql::factory();
        $newLang->setDebug(false);
        foreach ($firstLang as $firstLangEntry) {
            $newLang->setTable(\rex::getTable('sprog_wildcard'));

            foreach ($fields as $key => $value) {
                if ($value == 'pid') {
                    echo '';
                } elseif ($value == 'clang_id') {
                    $newLang->setValue('clang_id', $ep->getParam('clang')->getId());
                } else {
                    $newLang->setValue($value, $firstLangEntry->getValue($value));
                }
            }

            $newLang->insert();
        }
    }

    public static function clangDeleted(\rex_extension_point $ep)
    {
        $deleteLang = \rex_sql::factory();
        $deleteLang->setQuery('DELETE FROM '.\rex::getTable('sprog_wildcard').' WHERE clang_id=?', [$ep->getParam('clang')->getId()]);
    }

    public static function wildcardFormControlElement(\rex_extension_point $ep)
    {
        $subject = $ep->getSubject();
        $subject['delete'] = '';
        $ep->setSubject($subject);
    }
}
