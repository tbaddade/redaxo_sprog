<?php

declare(strict_types=1);

namespace Sprog\Mt;

use JsonException;
use Sprog\Exception\ProviderException;

use function in_array;
use function is_array;
use function is_string;
use function sprintf;

use const CURLINFO_HTTP_CODE;
use const CURLOPT_CONNECTTIMEOUT;
use const CURLOPT_FOLLOWLOCATION;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_POST;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_SSL_VERIFYHOST;
use const CURLOPT_SSL_VERIFYPEER;
use const CURLOPT_TIMEOUT;
use const CURLOPT_URL;
use const JSON_THROW_ON_ERROR;
use const PHP_QUERY_RFC3986;

/**
 * MT-Provider für DeepL (https://www.deepl.com/de/docs-api).
 *
 * - Free-Accounts: API-Key endet auf ":fx" → Endpoint api-free.deepl.com
 * - Pro-Accounts: kein Suffix → Endpoint api.deepl.com
 *
 * Auth über `Authorization: DeepL-Auth-Key <key>`-Header. Glossar-Support
 * läuft DeepL-side über glossary_ids — wir geben das Glossar aktuell nicht
 * mit; DeepL-Glossary-Integration kommt in einer eigenen Tranche.
 *
 * Sicherheits-Hinweise:
 *   - Der API-Key landet nie in Exception-Messages (Filter im error-mapping).
 *   - Connect-Timeout 10s, Total-Timeout 30s — wir blockieren das Backend
 *     nicht beliebig lang.
 *   - SSL-Verify ist explizit aktiv (cURL-Default, hier zusätzlich gesetzt).
 *   - Response wird mit JSON_THROW_ON_ERROR + Depth-Limit 16 geparst.
 */
final class DeepLProvider implements ProviderInterface
{
    private const ENDPOINT_FREE = 'https://api-free.deepl.com/v2/translate';
    private const ENDPOINT_PRO = 'https://api.deepl.com/v2/translate';

    private const CONNECT_TIMEOUT = 10;
    private const REQUEST_TIMEOUT = 30;

    public function __construct(
        private readonly string $apiKey,
    ) {}

    public function name(): string
    {
        return 'deepl';
    }

    public function isConfigured(): bool
    {
        return '' !== trim($this->apiKey);
    }

    /**
     * DeepL deckt die wichtigsten europäischen Sprachen ab. Wir whitelisten
     * sie hart — neue Sprachen können später additiv ergänzt werden, sobald
     * der Bedarf da ist.
     *
     * @see https://www.deepl.com/docs-api/translate-text/translate-text/
     */
    public function supports(string $sourceLang, string $targetLang): bool
    {
        static $supported = [
            'bg', 'cs', 'da', 'de', 'el', 'en', 'es', 'et', 'fi', 'fr',
            'hu', 'id', 'it', 'ja', 'ko', 'lt', 'lv', 'nb', 'nl', 'pl',
            'pt', 'ro', 'ru', 'sk', 'sl', 'sv', 'tr', 'uk', 'zh',
        ];

        return in_array($sourceLang, $supported, true) && in_array($targetLang, $supported, true);
    }

    public function translate(
        string $text,
        string $sourceLang,
        string $targetLang,
        array $glossary = [],
        ?string $context = null,
    ): TranslationResult {
        if (!$this->isConfigured()) {
            throw new ProviderException('DeepL-API-Key nicht konfiguriert.', $this->name());
        }
        if ('' === $text) {
            // DeepL würde leere Strings ablehnen — wir kürzen den Roundtrip.
            return new TranslationResult(
                text: '',
                confidence: null,
                provider: $this->name(),
                meta: ['note' => 'empty input skipped'],
            );
        }

        // $glossary: TODO(v3) — DeepL glossary_ids-Endpoint binden. Heute landen
        // Glossar-Einträge nur im Provider-neutralen NoopProvider-Pfad.
        // $context: nicht weitergereicht — DeepL hat kein äquivalentes Feld.
        $params = [
            'text' => $text,
            'source_lang' => strtoupper($sourceLang),
            'target_lang' => strtoupper($targetLang),
        ];

        [$httpStatus, $body] = $this->postForm($this->endpoint(), $params);

        if (200 !== $httpStatus) {
            throw new ProviderException($this->mapHttpError($httpStatus), $this->name());
        }

        try {
            /** @var array<string,mixed> $decoded */
            $decoded = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ProviderException('DeepL-Antwort konnte nicht als JSON gelesen werden.', $this->name(), $e);
        }

        $translations = $decoded['translations'] ?? null;
        if (!is_array($translations) || !isset($translations[0]) || !is_array($translations[0])) {
            throw new ProviderException('DeepL-Antwort enthält keine translations.', $this->name());
        }

        $first = $translations[0];
        $translated = isset($first['text']) && is_string($first['text']) ? $first['text'] : '';
        $detected = isset($first['detected_source_language']) && is_string($first['detected_source_language'])
            ? strtolower($first['detected_source_language'])
            : null;

        return new TranslationResult(
            text: $translated,
            // DeepL gibt keine Confidence-Werte zurück.
            confidence: null,
            provider: $this->name(),
            meta: [
                'detected_source_language' => $detected,
                'endpoint_tier' => $this->isFreeTier() ? 'free' : 'pro',
            ],
        );
    }

    private function endpoint(): string
    {
        return $this->isFreeTier() ? self::ENDPOINT_FREE : self::ENDPOINT_PRO;
    }

    private function isFreeTier(): bool
    {
        return str_ends_with($this->apiKey, ':fx');
    }

    /**
     * @param array<string, string> $params
     *
     * @return array{0: int, 1: string} [http_status, raw_body]
     */
    private function postForm(string $url, array $params): array
    {
        if ('' === $url) {
            // Defensive: CURLOPT_URL wird in der cURL-Lib als non-empty-string
            // erwartet — leerer String hier würde stumm zu einem Fehler-Response
            // führen statt zu einer klaren Exception.
            throw new ProviderException('cURL-URL darf nicht leer sein.', $this->name());
        }

        $ch = curl_init();
        if (false === $ch) {
            throw new ProviderException('cURL konnte nicht initialisiert werden.', $this->name());
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_HTTPHEADER => [
                'Authorization: DeepL-Auth-Key ' . $this->apiKey,
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
                'User-Agent: sprog-redaxo/2.0',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $errstr = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (false === $body || 0 !== $errno) {
            // Fehlertext sanitisieren — falls cURL aus irgendwelchen Gründen
            // den API-Key ausspuckt (z.B. via debug-output), niemals weiterleiten.
            $clean = $this->sanitizeForLog($errstr);
            throw new ProviderException(sprintf('Netzwerk-Fehler beim DeepL-Aufruf (cURL #%d): %s', $errno, $clean), $this->name());
        }

        return [$status, (string) $body];
    }

    private function mapHttpError(int $status): string
    {
        return match ($status) {
            400 => 'Ungültige Anfrage an DeepL (HTTP 400).',
            403 => 'DeepL hat den API-Key abgelehnt (HTTP 403).',
            404 => 'DeepL-Endpoint nicht erreichbar (HTTP 404).',
            413 => 'Text zu lang für eine DeepL-Anfrage (HTTP 413).',
            429 => 'DeepL-Rate-Limit erreicht (HTTP 429). Bitte später erneut versuchen.',
            456 => 'DeepL-Kontingent aufgebraucht (HTTP 456).',
            500, 503 => 'DeepL nicht erreichbar oder Wartung (HTTP ' . $status . ').',
            default => 'DeepL hat HTTP ' . $status . ' geantwortet.',
        };
    }

    /**
     * Entfernt den API-Key aus dem gegebenen Text, falls er versehentlich
     * in einer Fehlermeldung auftaucht. Defense-in-depth — sollte eigentlich
     * nie passieren, aber bei cURL-Verbose-Output o.ä. wäre der Schaden gross.
     */
    private function sanitizeForLog(string $text): string
    {
        if ('' === $this->apiKey) {
            return $text;
        }

        return str_replace($this->apiKey, '[REDACTED]', $text);
    }
}
