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

    public static function getRegexp($value = '.*?')
    {
        return '@' . preg_quote(trim( self::getOpenTag() )) . '\s*' . $value . '\s*' . preg_quote(trim( self::getCloseTag() )) . '@';
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
            $clang_id = \rex_clang::getCurrentId();
        }

        $sql = \rex_sql::factory();
        $sql->setQuery('SELECT `wildcard`, `replace` FROM ' . \rex::getTable('wildcard') . ' WHERE clang_id = "' . $clang_id . '"');
        
        $search  = array();
        $replace = array();
        $rows = $sql->getRows();
        
        for ($i = 1; $i <= $rows; $i++, $sql->next()) {

            $search[]  = self::getRegexp($sql->getValue('wildcard'));
            $replace[] = nl2br($sql->getValue('replace'));

        }

        return preg_replace($search, $replace, $content);
    }


    public static function getMissingWildcards()
    {
        $wildcards = array();

        if (\rex_addon::get('structure')->isAvailable() && \rex_plugin::get('structure', 'content')->isAvailable()) {

            // Slices der Artikel durchsuchen
            // Werden Slices gefunden, dann die Strukturartikel überschreiben
            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
            $fields = array(
                's.value'     => range('1', '20'),
            );

            $searchFields = array();
            $selectFields = array();
            foreach ($fields as $field => $numbers) {

                $concatFields = array();
                foreach ($numbers as $number) {
                    $concatFields[] = $field . $number;
                    $searchFields[] = $field . $number . ' RLIKE "' . mysql_real_escape_string( preg_quote(trim(self::getOpenTag())) . '.*' . preg_quote(trim(self::getCloseTag())) ) . '"';

                }
                $selectFields[] = 'CONCAT_WS("|", ' . implode(',', $concatFields) . ') AS subject';

            }


            $fields = $searchFields;

            $sql_query  = ' SELECT      s.article_id AS id,
                                        s.clang_id,
                                        s.ctype_id,
                                        ' . implode(', ', $selectFields) . '
                            FROM        ' . \rex::getTable('article_slice') . ' AS s
                                LEFT JOIN
                                        ' . \rex::getTable('article') . ' AS a
                                    ON  (s.article_id = a.id AND s.clang_id = a.clang_id)
                            WHERE       ' . implode(' OR ', $fields) . '
                            ';

            $sql = \rex_sql::factory();
            //$sql->setDebug();
            $sql->setQuery($sql_query);

            if ($sql->getRows() >= 1) {
                $items = $sql->getArray();

                foreach ($items as $item) {

                    preg_match_all(self::getRegexp(), $item['subject'], $matchesSubject, PREG_SET_ORDER);
                    
                    foreach ($matchesSubject as $match) {
                        $wildcards[$match[0]]['wildcard'] = str_replace(array(self::getOpenTag(), self::getCloseTag()), '', $match[0]);
                        $wildcards[$match[0]]['url'] = \rex_url::backendController(
                                                            array(
                                                                  'page' => 'content/edit', 
                                                                  'article_id' => $item['id'], 
                                                                  'mode' => 'edit', 
                                                                  'clang' => $item['clang_id'], 
                                                                  'ctype' => $item['ctype_id']
                                                            )
                                                        );
                    }

                }

            }


            if (count($wildcards)) {

                $sql_query = '
                                SELECT  CONCAT("' . mysql_real_escape_string(self::getOpenTag()) . '", wildcard, "' . mysql_real_escape_string(self::getCloseTag()) . '") AS wildcard 
                                FROM    ' . \rex::getTable('wildcard') . ' 
                                WHERE   clang_id = "' . \rex_clang::getStartId() . '"';

                $sql = \rex_sql::factory();
                //$sql->setDebug();
                $sql->setQuery($sql_query);

                if ($sql->getRows() >= 1) {
                    $items = $sql->getArray();

                    foreach ($items as $item) {

                        if (isset($wildcards[ $item['wildcard'] ])) {
                            unset($wildcards[ $item['wildcard'] ]);
                        }
                    }
                }

            }

            return $wildcards;

        }

        return false;
    }

}
