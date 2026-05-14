<?php

/**
 * This file is part of the Sprog package.
 *
 * @author (c) Thomas Blum <thomas@addoff.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sprog\Compat;

use Sprog\Cache\CacheInvalidationBus;
use Sprog\Matcher\TokenMatcher;
use Sprog\Service\AbbreviationLookupService;

class Abbreviation
{
    /**
     * Statischer Holder für die LookupService-Instanz innerhalb eines Requests
     * — analog zu Compat\Wildcard. Service ist DI, der Caller hält ihn zentral.
     */
    private static ?AbbreviationLookupService $lookupService = null;

    private static function lookupService(): AbbreviationLookupService
    {
        if (null !== self::$lookupService) {
            return self::$lookupService;
        }
        self::$lookupService = AbbreviationLookupService::create();
        CacheInvalidationBus::default()->register(self::$lookupService);

        return self::$lookupService;
    }

    public static function resetLookupService(): void
    {
        self::$lookupService = null;
    }

    /**
     * Wraps every configured abbreviation found inside the <body> with
     * <abbr title="…">…</abbr>.
     *
     * Datenquelle ab v2 ist der AbbreviationLookupService (v2 zuerst,
     * v1 als Fallback). Das Replacement-Pattern ist 1:1 das v1-Pattern,
     * aber alle Abbreviations laufen in einem einzigen Pass über den
     * TokenMatcher — statt N preg_replace_callback-Aufrufen.
     */
    public static function parse($content, $clangId = null)
    {
        if (!\rex_clang::exists($clangId)) {
            $clangId = \rex_clang::getCurrentId();
        }

        $map = self::lookupService()->allForClang((int) $clangId);
        if ([] === $map) {
            return $content;
        }

        $matcher = new TokenMatcher(
            $map,
            static function (string $matched, mixed $text): string {
                if (!is_string($text) || '' === $text) {
                    return $matched;
                }

                // rex_escape schützt den title-Attribut-Wert gegen XSS aus
                // dem Übersetzungs-Text — wichtig, weil $text aus der DB
                // kommt und ggf. von Übersetzern bearbeitet wurde.
                return sprintf('<abbr title="%s">%s</abbr>', rex_escape($text), $matched);
            },
        );

        return $matcher->replaceInBody((string) $content);
    }
}
