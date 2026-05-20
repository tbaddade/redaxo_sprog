<?php

declare(strict_types=1);

namespace Sprog\Matcher;

use Closure;

use function strlen;

/**
 * Multi-Pattern-Matcher für Inhalt-Ersetzungen.
 *
 * Statt jeden Eintrag (Abbreviation, Foreignword, …) mit einer eigenen
 * preg_replace_callback-Runde anzugehen — Bestandsverhalten in v1 mit
 * Aufwand O(Doc × Anzahl Patterns) — kompiliert dieser Matcher alle
 * Trigger zu einem einzigen, alternation-basierten Regex und macht den
 * Replace in einem einzigen Pass.
 *
 * Trie-Matching (Aho-Corasick) wäre noch schneller, aber das native
 * PCRE2-Engine in PHP 8.4 baut Alternation-Patterns intern bereits als
 * DFA auf; Faktor 10–100 gegenüber N × preg_replace ist im Bench
 * realistisch, ohne dass wir eigenen Trie-Code pflegen müssen.
 *
 * Das Match-Pattern bleibt 1:1 das v1-Pattern für Abbreviation, damit
 * Tag-Awareness, Wortgrenzen und das Verhalten an Sonderzeichen gleich
 * sind. Nur die Replacement-Strategie wechselt (mehrere Trigger
 * gleichzeitig).
 */
final class TokenMatcher
{
    private ?string $compiledRegex = null;

    /**
     * @param array<string, mixed>           $patterns key = Trigger-String, value = Payload für den Callback
     * @param Closure(string, mixed): string $callback liefert das Replacement für einen konkreten Treffer
     */
    public function __construct(
        private readonly array $patterns,
        private readonly Closure $callback,
    ) {}

    /**
     * Ersetzt im gesamten Content.
     */
    public function replace(string $content): string
    {
        $regex = $this->compile();
        if ('' === $regex || '' === $content) {
            return $content;
        }

        $patterns = $this->patterns;
        $callback = $this->callback;

        $result = preg_replace_callback(
            $regex,
            static function (array $m) use ($patterns, $callback): string {
                $matched = (string) ($m[0] ?? '');
                if ('' === $matched) {
                    return $matched;
                }

                $payload = $patterns[$matched] ?? null;

                return $callback($matched, $payload);
            },
            $content,
        );

        // preg_replace_callback liefert NULL bei Engine-Fehler — defensiv:
        // dann lieber den Originalinhalt zurückgeben als ein leeres Frontend.
        return $result ?? $content;
    }

    /**
     * Ersetzt nur innerhalb des <body>…</body>-Blocks.
     * Wird von Abbreviation / Foreignword genutzt, damit Markup im <head>
     * (z.B. JSON-LD, Meta-Tags) nicht versehentlich verändert wird.
     */
    public function replaceInBody(string $html): string
    {
        if ('' === $this->compile()) {
            return $html;
        }

        if (1 !== preg_match('|<body[^>]*>(.*)</body>|msU', $html, $body)) {
            // Kein body-Tag (z.B. AJAX-Fragment) — wir machen nichts statt
            // versehentlich am Markup ausserhalb des body zu drehen.
            return $html;
        }

        $original = $body[1];
        $replaced = $this->replace($original);

        if ($replaced === $original) {
            return $html;
        }

        return str_replace($original, $replaced, $html);
    }

    private function compile(): string
    {
        if (null !== $this->compiledRegex) {
            return $this->compiledRegex;
        }

        if ([] === $this->patterns) {
            return $this->compiledRegex = '';
        }

        // Längere Trigger zuerst — wichtig, weil PCRE-Alternation greedy
        // left-to-right matched: ohne Sortierung würde "WHO" gewinnen,
        // bevor "WHOM" zum Zug käme.
        $triggers = array_keys($this->patterns);
        usort($triggers, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        // preg_quote schützt vor Regex-Injection — beliebige Bestandsdaten
        // landen sicher als Literal im Pattern.
        $escaped = array_map(static fn (string $t): string => preg_quote($t, '/'), $triggers);

        /*
         * Match-Pattern (1:1 v1-Verhalten):
         *   (?!<[^<>]*?)   — nicht innerhalb eines öffnenden Tags
         *   (?<![?.&])     — nicht direkt nach Satzzeichen/HTML-Entity-Start
         *   \b…\b          — Wortgrenzen
         *   (?!:)          — nicht gefolgt von Doppelpunkt (URL-Schemas)
         *   (?![^<>]*?>)   — nicht innerhalb eines schliessenden Tags
         *
         * "?>" beendet in //- oder #-Kommentaren den PHP-Block — wir
         * brauchen daher den C-Style-Block-Kommentar für diese Zeilen.
         */
        $this->compiledRegex = '/(?!<[^<>]*?)(?<![?.&])\b(?:' . implode('|', $escaped) . ')\b(?!:)(?![^<>]*?>)/u';

        return $this->compiledRegex;
    }
}
