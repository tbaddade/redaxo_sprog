<?php

declare(strict_types=1);

namespace Sprog\Service;

use InvalidArgumentException;
use rex_config;
use Sprog\Exception\ProviderException;
use Sprog\Mt\DeepLProvider;
use Sprog\Mt\NoopProvider;
use Sprog\Mt\ProviderInterface;
use Sprog\Mt\TranslationResult;

/**
 * Orchestrator für Machine-Translation-Provider.
 *
 * Hält eine Registry aller registrierten Provider und delegiert
 * Übersetzungs-Anfragen anhand des Provider-Namens. Konkrete Provider
 * (DeepL, Claude, OpenAI) werden vom Caller injiziert oder über
 * registerProvider() nachgereicht — der Service selbst hat keine
 * direkten HTTP-Calls oder API-Keys.
 *
 * Validierungen passieren hier (Sprachcodes, Längen, Glossar-Form), sodass
 * die einzelnen Provider sich darauf verlassen können. Das hält die Provider
 * minimal und konzentriert das Input-Hardening an einer Stelle.
 */
final class MtService
{
    /**
     * ISO 639-1: genau zwei lowercase Buchstaben. Strenger als nötig (es gibt
     * 3-Buchstaben-Codes), aber das ist v2-Scope; Erweiterung später möglich.
     */
    private const LANG_REGEX = '/^[a-z]{2}$/';

    /** Maximale Länge für Context-Hint. Schützt Prompt-Größe bei LLM-Providern. */
    private const MAX_CONTEXT_LENGTH = 500;

    /** Maximale Länge eines Glossar-Eintrags (key bzw. value). */
    private const MAX_GLOSSARY_TERM_LENGTH = 200;

    /**
     * @param array<string, ProviderInterface> $providers Provider-Name => Instanz
     */
    public function __construct(
        private array $providers,
        private readonly string $defaultProvider = 'noop',
    ) {
        foreach ($providers as $key => $provider) {
            if (!is_string($key) || $key !== $provider->name()) {
                throw new InvalidArgumentException(
                    'Provider-Registry-Key muss dem name() des Providers entsprechen.',
                );
            }
        }
    }

    /**
     * Standard-Factory. Registriert immer den NoopProvider als Default,
     * und zusätzlich alle Provider, deren rex_config-Keys gesetzt sind.
     * So müssen Backend-Pages keine Provider manuell aufbauen.
     */
    public static function create(): self
    {
        $providers = [];

        $noop                    = new NoopProvider();
        $providers[$noop->name()] = $noop;

        $deeplKey = (string) rex_config::get('sprog', 'mt_deepl_key', '');
        if ('' !== trim($deeplKey)) {
            $deepl                    = new DeepLProvider($deeplKey);
            $providers[$deepl->name()] = $deepl;
        }

        return new self($providers);
    }

    /**
     * Übersetzt einen Text. Liefert das Provider-Ergebnis durch — kein
     * Persistieren, kein Status-Wechsel, kein Activity-Log. Das macht der
     * Aufrufer (TranslationService bzw. die Backend-Page).
     *
     * @param array<string, string> $glossary
     *
     * @throws ProviderException
     */
    public function translate(
        string $text,
        string $sourceLang,
        string $targetLang,
        ?string $providerName = null,
        array $glossary = [],
        ?string $context = null,
    ): TranslationResult {
        $this->assertValidLang($sourceLang, 'sourceLang');
        $this->assertValidLang($targetLang, 'targetLang');
        if ($sourceLang === $targetLang) {
            throw new InvalidArgumentException('sourceLang und targetLang sind identisch.');
        }
        if (null !== $context && strlen($context) > self::MAX_CONTEXT_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'context übersteigt die Maximallänge von %d Zeichen.',
                self::MAX_CONTEXT_LENGTH,
            ));
        }
        $this->assertValidGlossary($glossary);

        $name     = $providerName ?? $this->defaultProvider;
        $provider = $this->providers[$name] ?? null;

        if (null === $provider) {
            throw new ProviderException(
                sprintf('MT-Provider "%s" ist nicht registriert.', $name),
                $name,
            );
        }
        if (!$provider->isConfigured()) {
            throw new ProviderException(
                sprintf('MT-Provider "%s" ist nicht konfiguriert (z.B. fehlender API-Key).', $name),
                $name,
            );
        }
        if (!$provider->supports($sourceLang, $targetLang)) {
            throw new ProviderException(
                sprintf(
                    'MT-Provider "%s" unterstützt die Sprachrichtung %s → %s nicht.',
                    $name,
                    $sourceLang,
                    $targetLang,
                ),
                $name,
            );
        }

        return $provider->translate($text, $sourceLang, $targetLang, $glossary, $context);
    }

    /**
     * Provider zur Registry hinzufügen (z.B. zur Test-Zeit oder durch ein
     * Drittaddon, das einen weiteren Provider registriert).
     */
    public function registerProvider(ProviderInterface $provider): void
    {
        $this->providers[$provider->name()] = $provider;
    }

    /**
     * @return list<string> Namen aller Provider, die als konfiguriert gelten.
     *                      Noop ist immer dabei; echte Provider erst, wenn
     *                      ihre API-Konfiguration vollständig ist.
     */
    public function configuredProviderNames(): array
    {
        $names = [];
        foreach ($this->providers as $name => $provider) {
            if ($provider->isConfigured()) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @return array<string, ProviderInterface>
     */
    public function providers(): array
    {
        return $this->providers;
    }

    private function assertValidLang(string $code, string $argName): void
    {
        if (1 !== preg_match(self::LANG_REGEX, $code)) {
            throw new InvalidArgumentException(sprintf(
                '%s "%s" ist kein ISO-639-1-Code (zwei Kleinbuchstaben erwartet).',
                $argName,
                $code,
            ));
        }
    }

    /**
     * @param array<string, string> $glossary
     */
    private function assertValidGlossary(array $glossary): void
    {
        foreach ($glossary as $source => $target) {
            if (!is_string($source) || !is_string($target)) {
                throw new InvalidArgumentException(
                    'Glossar muss eine string=>string-Map sein.',
                );
            }
            if ('' === $source || '' === $target) {
                throw new InvalidArgumentException(
                    'Glossar-Einträge dürfen weder leeren Schlüssel noch leeren Wert haben.',
                );
            }
            if (strlen($source) > self::MAX_GLOSSARY_TERM_LENGTH
                || strlen($target) > self::MAX_GLOSSARY_TERM_LENGTH
            ) {
                throw new InvalidArgumentException(sprintf(
                    'Glossar-Einträge dürfen je maximal %d Zeichen lang sein.',
                    self::MAX_GLOSSARY_TERM_LENGTH,
                ));
            }
        }
    }
}
