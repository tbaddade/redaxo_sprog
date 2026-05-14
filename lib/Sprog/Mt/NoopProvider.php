<?php

declare(strict_types=1);

namespace Sprog\Mt;

/**
 * Default-Stub für die MT-Pipeline.
 *
 * Liefert den Quelltext unverändert zurück; nützlich
 *   - in Tests (deterministisch, ohne externe HTTP-Calls),
 *   - als Fallback, wenn kein echter Provider konfiguriert ist (Backend zeigt
 *     dann den Quelltext und der Übersetzer weiss klar, dass MT inaktiv ist),
 *   - als Sanity-Check für den Service-Layer, ohne API-Keys einrichten zu
 *     müssen.
 *
 * Confidence ist immer NULL — wir markieren bewusst, dass kein echter MT-Lauf
 * stattgefunden hat. Der TranslationService kann dann entscheiden, ob er das
 * Ergebnis überhaupt als MT-Draft speichern will.
 */
final class NoopProvider implements ProviderInterface
{
    public function name(): string
    {
        return 'noop';
    }

    public function isConfigured(): bool
    {
        // Bewusst true — der Noop-Provider braucht keine Konfiguration und
        // ist immer verfügbar als Sanity-Default.
        return true;
    }

    public function supports(string $sourceLang, string $targetLang): bool
    {
        return true;
    }

    public function translate(
        string $text,
        string $sourceLang,
        string $targetLang,
        array $glossary = [],
        ?string $context = null,
    ): TranslationResult {
        return new TranslationResult(
            text:       $text,
            confidence: null,
            provider:   $this->name(),
            meta:       [
                'note'        => 'noop provider returned source verbatim',
                'source_lang' => $sourceLang,
                'target_lang' => $targetLang,
            ],
        );
    }
}
