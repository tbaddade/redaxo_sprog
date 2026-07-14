<?php

declare(strict_types=1);

namespace Sprog\Controller\Inbox;

use rex_clang;
use rex_i18n;
use rex_user;
use Sprog\Http\JsonResponse;
use Sprog\Repository\TranslationRepository;
use Sprog\Repository\UnitRepository;
use Sprog\Service\GlossaryService;
use Sprog\Service\MtService;
use Sprog\Support\BaseLang;
use Sprog\Support\ClangBase;
use Throwable;

use function in_array;

/**
 * Endpoint POST ?func=mt — MT-Vorschlag für eine Sprache im Inbox-Akkordeon.
 *
 * Liest den Quelltext der Start-Clang, ruft MtService::translate() auf und gibt
 * den Vorschlag als JSON zurück. Speichert NICHT: der Client (sprog.inbox.js)
 * trägt den Text ins Textarea, der bestehende Blur-Auto-Save persistiert ihn
 * dann samt MT-Marker (mt_provider/mt_confidence wandern in der Save-Payload).
 *
 * Portiert aus der früheren Editor-Page. CSRF nutzt das page-globale
 * sprog_inbox_save-Token (gemeinsam mit Save/Transition).
 */
final class MtController
{
    /**
     * Zeitlimit (Sekunden) für den MT-Request. LLM-Aufrufe — besonders lokale
     * Modelle über Ollama — überschreiten leicht die Standard-30-Sekunden von
     * PHP; dann bricht der Request mit einem nicht abfangbaren Fatal ab (der
     * Client sieht nur „HTTP 500"). MT ist eine bewusste, vom User angestoßene
     * Warte-Aktion, daher heben wir das Limit an — aber gedeckelt, damit ein
     * hängender Provider keinen PHP-Worker unbegrenzt blockiert.
     */
    private const MT_EXECUTION_TIME_LIMIT = 120;

    public function __construct(
        private readonly TranslationRepository $translations,
    ) {}

    public static function create(): self
    {
        return new self(new TranslationRepository());
    }

    public function handle(rex_user $user): never
    {
        $unitId = (int) rex_request('unit_id', 'int', 0);
        $targetClangId = (int) rex_request('clang_id', 'int', 0);
        $providerRequest = trim((string) rex_request('provider', 'string', ''));

        JsonResponse::ensureCsrf('sprog_inbox_save', rex_i18n::rawMsg('sprog_inbox_mt_csrf'));

        if ($unitId <= 0 || !rex_clang::exists($targetClangId)) {
            JsonResponse::badRequest(rex_i18n::rawMsg('sprog_inbox_mt_unknown_clang'));
        }
        if (!$user->getComplexPerm('clang')->hasPerm($targetClangId)) {
            JsonResponse::forbidden(rex_i18n::rawMsg('sprog_inbox_mt_no_perm'));
        }
        // Abgeleitete Sprache (clang_base → andere Sprache) spiegelt eine andere
        // und wird nicht eigenständig übersetzt — kein gültiges MT-Ziel.
        if (ClangBase::isDerived($targetClangId)) {
            JsonResponse::badRequest(rex_i18n::rawMsg('sprog_inbox_target_derived'));
        }

        // Quelltext kommt implizit aus der Basissprache (konfigurierbar,
        // Fallback Start-Clang).
        $sourceClangId = BaseLang::clangId();
        if ($sourceClangId === $targetClangId) {
            JsonResponse::badRequest(rex_i18n::rawMsg('sprog_inbox_mt_same_lang'));
        }

        $sourceClang = rex_clang::get($sourceClangId);
        $targetClang = rex_clang::get($targetClangId);
        if (null === $sourceClang || null === $targetClang) {
            JsonResponse::badRequest(rex_i18n::rawMsg('sprog_inbox_mt_unknown_clang'));
        }

        $sourceTranslation = $this->translations->findForUnitAndClang($unitId, $sourceClangId);
        if (null === $sourceTranslation || '' === trim($sourceTranslation->value)) {
            JsonResponse::badRequest(rex_i18n::rawMsg('sprog_inbox_mt_no_source', $sourceClang->getName()));
        }

        try {
            $mt = MtService::create();
            $configured = $mt->configuredProviderNames();

            // Provider-Auswahl: explizit aus Request, sonst erster echter
            // Provider. Fallback nicht auf 'noop' (das gäbe nur den Quelltext
            // zurück und wäre für den User nutzlos).
            $useProvider = null;
            if ('' !== $providerRequest && in_array($providerRequest, $configured, true) && 'noop' !== $providerRequest) {
                $useProvider = $providerRequest;
            } else {
                foreach ($configured as $name) {
                    if ('noop' !== $name) {
                        $useProvider = $name;
                        break;
                    }
                }
            }

            if (null === $useProvider) {
                JsonResponse::badRequest(rex_i18n::rawMsg('sprog_inbox_mt_no_provider'));
            }

            $sourceCode = strtolower($sourceClang->getCode());
            $targetCode = strtolower($targetClang->getCode());

            // Glossar-Paare (verbindliche Begriffe) + Kontext (Bereich/Notiz der
            // Einheit) anreichern — der LLM-Provider verwertet beides im Prompt,
            // DeepL ignoriert es. Welche Sprachrichtung möglich ist, entscheidet
            // der jeweilige Provider über supports() (kein harter Code-Check mehr).
            $glossary = GlossaryService::create()->mapForPair($sourceClangId, $targetClangId);
            $context = $this->buildContext($unitId);

            // Der eigentliche Übersetzungs-Call kann dauern (v.a. lokale LLMs
            // über Ollama). Zeitlimit erst hier anheben, nachdem alle günstigen
            // Validierungen durch sind.
            set_time_limit(self::MT_EXECUTION_TIME_LIMIT);

            $result = $mt->translate(
                $sourceTranslation->value,
                $sourceCode,
                $targetCode,
                $useProvider,
                $glossary,
                $context,
            );

            JsonResponse::ok([
                'text' => $result->text,
                'provider' => $result->provider,
                'confidence' => $result->confidence,
            ]);
        } catch (Throwable $e) {
            JsonResponse::internalError(rex_i18n::rawMsg('sprog_inbox_mt_failed', $e->getMessage()));
        }
    }

    /**
     * Kontext-Hinweis für den Übersetzer/LLM aus Bereich + Notiz der Einheit.
     * Gibt null zurück, wenn nichts Verwertbares vorliegt (Provider ohne
     * Kontext-Support ignorieren den Wert ohnehin).
     */
    private function buildContext(int $unitId): ?string
    {
        $unit = (new UnitRepository())->find($unitId);
        if (null === $unit) {
            return null;
        }

        $parts = [];
        if ('' !== $unit->context) {
            $parts[] = 'Bereich: ' . $unit->context;
        }
        if (null !== $unit->notes && '' !== trim($unit->notes)) {
            $parts[] = 'Hinweis: ' . $unit->notes;
        }

        return [] === $parts ? null : implode('. ', $parts);
    }
}
