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

class Abbreviation
{
    public static function parse($content, $clangId = null)
    {
        if (!\rex_clang::exists($clangId)) {
            $clangId = \rex_clang::getCurrentId();
        }

        preg_match_all('|<body[^>]*>(.*)</body>|msU', $content, $body);

        if (!isset($body[1][0])) {
            return $content;
        }
        $bodyReplace = $body[1][0];

        $sql = \rex_sql::factory();
        $sql->setQuery('SELECT `abbreviation`, `text` FROM '.\rex::getTable('sprog_abbreviation').' WHERE `clang_id` = :clangId AND `status` = 1', ['clangId' => $clangId]);
        $items = $sql->getArray();

        foreach ($items as $item) {
            $bodyReplace = preg_replace_callback('|(?!<[^<>]*?)(?<![?.&])\b'.$item['abbreviation'].'\b(?!:)(?![^<>]*?>)|msU', function ($matches) use ($item) {
                    return sprintf('<abbr title="%s">%s</abbr>', rex_escape($item['text']), $matches[0]);
            }, $bodyReplace);
        }

        return str_replace($body[1][0], $bodyReplace, $content);
    }
}
