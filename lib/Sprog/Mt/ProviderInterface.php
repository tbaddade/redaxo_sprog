<?php

declare(strict_types=1);

namespace Sprog\Mt;

use Sprog\Exception\ProviderException;

/**
 * Vertrag für einen Machine-Translation-Provider (DeepL, Claude, OpenAI, …).
 *
 * Implementierungen werden im MtService registriert und über ihren `name()`
 * adressiert. Sprachcodes folgen ISO 639-1 (`de`, `en`, `fr`, …) — Provider,
 * die mit Variants arbeiten (z.B. DeepL „EN-US" vs. „EN-GB"), übersetzen das
 * intern, expose aber den Basis-Code nach aussen.
 *
 * Provider sind nicht responsibility für Status-Übergänge, Hashing oder
 * Persistenz — das macht der TranslationService, der das Ergebnis dieses
 * Interface als Roh-Übersetzung weiterverarbeitet.
 */
interface ProviderInterface
{
    /**
     * Eindeutiger, lowercase Provider-Name (z.B. 'deepl', 'claude', 'openai',
     * 'noop'). Wird als rex_config-Key-Prefix verwendet und im Activity-Log
     * gespeichert; daher: nur a-z 0-9 _, max 32 Zeichen.
     */
    public function name(): string;

    /**
     * Ist die Konfiguration vollständig (API-Key gesetzt, Quota usw.)?
     * MtService nutzt das, um nur konfigurierte Provider in der Auswahl
     * anzubieten.
     */
    public function isConfigured(): bool;

    /**
     * Unterstützt der Provider die Sprachrichtung?
     * Source und Target sind ISO-639-1-Codes (lowercase, 2 Zeichen).
     */
    public function supports(string $sourceLang, string $targetLang): bool;

    /**
     * Übersetzt einen Text-Block.
     *
     * @param string                $text       Quell-Text, beliebig lang im Rahmen der Provider-Limits.
     * @param string                $sourceLang ISO-639-1, z.B. 'de'.
     * @param string                $targetLang ISO-639-1, z.B. 'en'.
     * @param array<string, string> $glossary   Optionale Glossar-Pairs (Source-Term => Target-Term).
     *                                          Provider, die Glossar-Support nativ haben (DeepL), nutzen den
     *                                          Provider-Mechanismus; andere (Claude/OpenAI) injizieren das in
     *                                          den System-Prompt.
     * @param ?string               $context    Optionaler Hinweis für den Übersetzer/das LLM (z.B. „CTA-Button
     *                                          im Footer", „rechtlicher Text"). Wird von DeepL ignoriert,
     *                                          von LLM-Providern in den Prompt aufgenommen.
     *
     * @throws ProviderException bei Provider-Fehlern (HTTP, Quota, ungültige Antwort, fehlende Konfiguration).
     */
    public function translate(
        string $text,
        string $sourceLang,
        string $targetLang,
        array $glossary = [],
        ?string $context = null,
    ): TranslationResult;
}
