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
        $ep->setSubject(Wildcard::replace($ep->getSubject()));
    }

    public static function clangAdded(\rex_extension_point $ep)
    {
        $firstLang = \rex_sql::factory();
        $firstLang->setQuery('SELECT * FROM ' . \rex::getTable('sprog_wildcard') . ' WHERE clang_id=?', [\rex_clang::getStartId()]);
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
        $deleteLang->setQuery('DELETE FROM ' . \rex::getTable('sprog_wildcard') . ' WHERE clang_id=?', [$ep->getParam('clang')->getId()]);
    }
}
