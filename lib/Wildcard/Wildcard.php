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


class Wildcard
{

    public static function getOpenTag()
    {
        return \rex_config::get('wildcard', 'open_tag', '{{ ');
    }

    public static function getCloseTag()
    {
        return \rex_config::get('wildcard', 'close_tag', ' }}');
    }


    /**
     * Returns the replaced content
     *
     * @param  string $content
     * @param  int $clang_id
     * @return string
     */
    public static function replace($content, $clang_id = null)
    {      

        if (trim($content) == '') {
            return $content;
        }

        if (! $clang_id) {
            $clang_id = rex_clang::getCurrentId();
        }

        $sql = rex_sql::factory();
        $sql->setQuery('SELECT wildcard, replace FROM ' . rex::getTable('wildcard') . ' WHERE clang_id = "' . $clang_id . '"');
        
        $search  = array();
        $replace = array();
        $rows = $sql->getRows();
        
        for ($i = 1; $i <= $rows; $i++, $sql->next()) {

            $search[]  = '@' . preg_quote(trim( Wildcard::getOpenTag() )) . '\s*' . $sql->getValue('wildcard') . '\s*' . preg_quote(trim( Wildcard::getCloseTag() )) . '@';
            $replace[] = nl2br($sql->getValue('replace'));

        }

        return preg_replace($search, $replace, $content);
    }

}
