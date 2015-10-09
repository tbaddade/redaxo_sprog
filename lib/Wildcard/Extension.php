<?php

/**
 * This file is part of the Wildcard package.
 *
 * @author (c) Thomas Blum <thomas@addoff.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Wildcard;

use \Wildcard\Wildcard;

class Extension
{

    public static function replace(\rex_extension_point $ep)
    {
        $ep->setSubject( Wildcard::replace( $ep->getSubject() ) );
    }



    public static function clangAdded($params)
    {
        $firstLang = \rex_sql::factory();
        $firstLang->setQuery('SELECT * FROM ' . rex::getTable('wildcard') . ' WHERE clang_id=?', [rex_clang::getStartId()]);
        $fields = $firstLang->getFieldnames();

        $newLang = \rex_sql::factory();
        // $newLang->setDebug();
        foreach ($firstLang as $firstLangEntry) {
            $newLang->setTable(rex::getTable('wiildcard'));

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



    public static function clangDeleted($params)
    {
        $deleteLang = \rex_sql::factory();
        $deleteLang->setQuery('DELETE FROM ' . rex::getTable('wildcard') . ' WHERE clang_id=?', [$ep->getParam('clang')->getId()]);
    }

}
