<?php

/**
 * This file is part of the Sprog package.
 *
 * @author (c) Thomas Blum <thomas@addoff.de>
 * @author (c) Robert Rupf <robert.rupf@maumha.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sprog;

class Foreignword
{
    static $languages = null;
    public static function getLanguages()
    {
        if (null === self::$languages) {
            self::$languages = preg_split('~,\s*~', \rex_config::get('sprog', 'foreignword_languages', 'en'));
        }

        return self::$languages;
    }

    public static function parse($content, $clangId = null)
    {
        if (!\rex_clang::exists($clangId)) {
            $clangId = \rex_clang::getCurrentId();
        }

        preg_match_all('|<body[^>]*>(.*)</body>|msU', $content, $body);

        if (!isset($body[1][0])) {
            return $content;
        }

        $sql = \rex_sql::factory();
        $sql->setQuery('SELECT `foreignword`, `lang` FROM '.\rex::getTable('sprog_foreignword').' WHERE `clang_id` = :clangId AND `status` = 1', ['clangId' => $clangId]);
        $items = $sql->getArray();

        $bodyReplace = '';
        foreach (preg_split('~(<script[^>]*>.*?</script>)~ism', $body[1][0], -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {

            if (str_starts_with($part, '<script')) {
                $bodyReplace .= $part;
                continue;
            }

            foreach ($items as $item) {
                $bodyReplace .= preg_replace_callback('|(?!<[^<>]*?)(?<![?.&])\b' . $item['foreignword'] . '\b(?!:)(?![^<>]*?>)|msU', function ($matches) use ($item) {
                    return sprintf('<span lang="%s">%s</span>', rex_escape($item['lang']), $matches[0]);
                }, $part);
            }
        }

        return str_replace($body[1][0], $bodyReplace, $content);
    }
}
