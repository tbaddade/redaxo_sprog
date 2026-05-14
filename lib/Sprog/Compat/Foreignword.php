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
use Sprog\Service\ForeignwordLookupService;

/**
 * Markiert konfigurierte Fremdwörter im HTML-Body mit einem
 * <span lang="xx">…</span>-Wrap, damit Screenreader die Wörter
 * mit korrekter Sprachausgabe lesen.
 *
 * Auf v2 neu eingeführt — auf master gab es bisher weder die Klasse
 * noch eine Backend-Page noch eine v1-Tabelle. Die zugrundeliegende
 * Tabelle rex_sprog_foreignword wird von install.php seit der
 * v2.0-dev-Tranche idempotent angelegt; v1-Daten aus xong/master
 * werden bei einem Cross-Branch-Merge automatisch unter
 * 'foreignword'-Namespace migrierbar.
 */
class Foreignword
{
    /**
     * Statischer Holder für die LookupService-Instanz innerhalb eines Requests
     * — analog zu Compat\Wildcard / Compat\Abbreviation.
     */
    private static ?ForeignwordLookupService $lookupService = null;

    private static function lookupService(): ForeignwordLookupService
    {
        if (null !== self::$lookupService) {
            return self::$lookupService;
        }
        self::$lookupService = ForeignwordLookupService::create();
        CacheInvalidationBus::default()->register(self::$lookupService);

        return self::$lookupService;
    }

    public static function resetLookupService(): void
    {
        self::$lookupService = null;
    }

    public static function parse(string $content, ?int $clangId = null): string
    {
        if (null === $clangId || !\rex_clang::exists($clangId)) {
            $clangId = \rex_clang::getCurrentId();
        }

        $map = self::lookupService()->allForClang($clangId);
        if ([] === $map) {
            return $content;
        }

        // Foreignwords ohne lang-Code würden zu einem nichts-bewirkenden
        // <span>-Wrap führen — daher rausfiltern, bevor der Matcher kompiliert.
        $withLang = array_filter($map, static fn (string $lang): bool => '' !== $lang);
        if ([] === $withLang) {
            return $content;
        }

        $matcher = new TokenMatcher(
            $withLang,
            static function (string $matched, mixed $lang): string {
                if (!is_string($lang) || '' === $lang) {
                    return $matched;
                }

                // rex_escape — obwohl der lang-Code im LookupService bereits
                // strikt validiert wurde ([a-z]{2}), bleibt die Escape-Routine
                // als Defense-in-Depth drin.
                return sprintf('<span lang="%s">%s</span>', rex_escape($lang), $matched);
            },
        );

        return $matcher->replaceInBody((string) $content);
    }
}
