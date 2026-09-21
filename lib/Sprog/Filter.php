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

abstract class Filter
{
    /**
     * Returns the name of the filter.
     */
    abstract public function name(): string;

    /**
     * Execute the filter.
     */
    abstract public function fire(string $value, string $arguments): string;

    /**
     * Zerlegt den Argument-String eines Filter-Aufrufs (`{{ key|filter(a, b) }}`)
     * in einzelne, getrimmte Argumente.
     *
     * Getrennt wird nur an Kommas auf Klammertiefe 0 außerhalb doppelter
     * Anführungszeichen — HTML-Attribute (`title="x, y"`) und Aufrufe
     * (`onclick="open('/x', 'popup')"`) bleiben ein Argument. Leere Argumente
     * bleiben erhalten (`a,,b` → drei Stück), damit nachfolgende Platzhalter
     * nicht verrutschen.
     *
     * Unbalancierte Klammern oder Anführungszeichen (`5" Rohr, 3`) gelten als
     * Literal, dann wird schlicht an jedem Komma getrennt. Einfache
     * Anführungszeichen werden bewusst nicht ausgewertet: ein Apostroph wie in
     * `Tom's, Baum` würde sonst das nächste Komma verschlucken. Quoting ist
     * damit kein Escape-Mechanismus, die Anführungszeichen bleiben Teil des
     * Werts.
     *
     * Öffentlich, damit Drittaddon-Filter (EP SPROG_FILTER) dieselbe Semantik
     * nutzen können.
     *
     * @return list<string>
     */
    public static function splitArguments(string $arguments): array
    {
        $trackParens = substr_count($arguments, '(') === substr_count($arguments, ')');
        $trackQuotes = 0 === substr_count($arguments, '"') % 2;

        $args = [];
        $current = '';
        $depth = 0;
        $inQuote = false;

        // Byteweise ist hier sicher: alle Steuerzeichen sind ASCII, und
        // UTF-8-Folgebytes (>= 0x80) kollidieren damit nie.
        $length = strlen($arguments);
        for ($i = 0; $i < $length; ++$i) {
            $char = $arguments[$i];

            if ($trackQuotes && '"' === $char) {
                $inQuote = !$inQuote;
            } elseif ($trackParens && !$inQuote && '(' === $char) {
                ++$depth;
            } elseif ($trackParens && !$inQuote && ')' === $char) {
                --$depth;
            } elseif (',' === $char && !$inQuote && $depth <= 0) {
                $args[] = trim($current);
                $current = '';
                continue;
            }

            $current .= $char;
        }
        $args[] = trim($current);

        return $args;
    }
}
