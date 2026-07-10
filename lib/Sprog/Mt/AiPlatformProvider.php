<?php

declare(strict_types=1);

namespace Sprog\Mt;

use FriendsOfRedaxo\AiPlatform\Service;
use rex_addon;
use Sprog\Exception\ProviderException;
use Throwable;

use function class_exists;
use function implode;
use function is_string;
use function preg_replace;
use function sprintf;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function substr;
use function substr_count;
use function trim;
use function ucfirst;

/**
 * MT-Provider auf Basis des ai_platform-AddOns (LLM: OpenAI, Claude, Gemini,
 * Ollama). Nutzt das dort als Standard gesetzte Text-Profil (Provider + Modell
 * + Parameter) über FriendsOfRedaxo\AiPlatform\Service::generateText().
 *
 * Anders als DeepL werden Glossar UND Kontext aktiv verwertet — beide fließen
 * in den System-Prompt. Ohne Sprachen-Whitelist: ein LLM deckt praktisch jede
 * Richtung ab (inkl. Locale-Codes wie de_at).
 *
 * WEICHE Abhängigkeit: Der Provider meldet sich nur als konfiguriert, wenn das
 * ai_platform-AddOn verfügbar UND ein Text-Profil als Default gesetzt ist.
 * Fehlt das AddOn, bleibt sprog voll funktionsfähig (kein `requires` in
 * package.yml, nur Laufzeit-Prüfung via class_exists + isAvailable).
 *
 * Der Nicht-Determinismus des LLM ist durch den Review-Workflow abgesichert:
 * das Ergebnis ist immer nur ein Vorschlag, den ein Reviewer freigibt.
 */
final class AiPlatformProvider implements ProviderInterface
{
    /**
     * Kurze Anzeigenamen je ai_platform-Provider-Slug. Die AddOn-eigenen
     * getProviders()-Labels ("Ollama (Lokal)", "OpenAI (GPT, DALL-E)") sind für
     * einen Button zu lang. Fallback für unbekannte Slugs: ucfirst($slug).
     */
    private const PROVIDER_LABELS = [
        'openai' => 'OpenAI',
        'anthropic' => 'Anthropic',
        'google' => 'Google',
        'ollama' => 'Ollama',
    ];

    public function name(): string
    {
        return 'ai_platform';
    }

    /**
     * Menschlich lesbarer Provider-Name des aktuell als Standard gesetzten
     * Text-Profils (z.B. "Ollama", "OpenAI") — für die Button-Beschriftung in
     * der Inbox ("KI (Ollama)"). Null, wenn nicht ermittelbar.
     */
    public function profileProviderName(): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }

        try {
            $profile = Service::getInstance()->getDefaultProfile('text');
        } catch (Throwable) {
            return null;
        }

        $slug = isset($profile['provider']) && is_string($profile['provider']) ? $profile['provider'] : '';
        if ('' === $slug) {
            return null;
        }

        return self::PROVIDER_LABELS[$slug] ?? ucfirst($slug);
    }

    public function isConfigured(): bool
    {
        if (!class_exists(Service::class) || !rex_addon::get('ai_platform')->isAvailable()) {
            return false;
        }

        // getDefaultProfile() wirft eine rex_exception, wenn kein Default-Text-
        // Profil gesetzt ist — genau dann ist der Provider nicht nutzbar.
        try {
            Service::getInstance()->getDefaultProfile('text');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function supports(string $sourceLang, string $targetLang): bool
    {
        // Ein LLM übersetzt praktisch jede Richtung (auch Locale-Varianten);
        // nur Quelle == Ziel ergibt keinen Sinn.
        return '' !== trim($sourceLang) && $sourceLang !== $targetLang;
    }

    public function translate(
        string $text,
        string $sourceLang,
        string $targetLang,
        array $glossary = [],
        ?string $context = null,
    ): TranslationResult {
        if (!$this->isConfigured()) {
            throw new ProviderException('ai_platform ist nicht nutzbar (AddOn fehlt oder kein Text-Profil gesetzt).', $this->name());
        }
        if ('' === trim($text)) {
            return new TranslationResult(
                text: '',
                confidence: null,
                provider: $this->name(),
                meta: ['note' => 'empty input skipped'],
            );
        }

        $systemPrompt = $this->buildSystemPrompt($sourceLang, $targetLang, $glossary, $context);

        try {
            // profileId = null → ai_platform nutzt sein Default-Text-Profil.
            $raw = Service::getInstance()->generateText($text, $systemPrompt);
        } catch (Throwable $e) {
            throw new ProviderException('ai_platform-Aufruf fehlgeschlagen: ' . $e->getMessage(), $this->name(), $e);
        }

        return new TranslationResult(
            text: $this->clean($raw),
            confidence: null, // LLM liefert keine verlässliche Confidence-Schätzung.
            provider: $this->name(),
            meta: ['source_lang' => $sourceLang, 'target_lang' => $targetLang],
        );
    }

    /**
     * Baut den System-Prompt: strenge Übersetzer-Rolle mit Schutz für
     * Platzhalter/HTML, angereichert um Glossar-Begriffe und Kontext.
     *
     * @param array<string, string> $glossary Source-Term => Target-Term
     */
    private function buildSystemPrompt(string $source, string $target, array $glossary, ?string $context): string
    {
        $lines = [
            sprintf('Du bist ein professioneller Fachübersetzer. Übersetze den Text von "%s" nach "%s".', $source, $target),
            'Gib ausschließlich die Übersetzung zurück – ohne Anführungszeichen, ohne Vor- oder Nachbemerkungen.',
            'Lasse Platzhalter der Form {{ … }}, HTML-Tags und Variablen unverändert. Erhalte Zeilenumbrüche und Formatierung.',
        ];

        if ([] !== $glossary) {
            $pairs = [];
            foreach ($glossary as $sourceTerm => $targetTerm) {
                $pairs[] = sprintf('%s → %s', $sourceTerm, $targetTerm);
            }
            $lines[] = 'Verwende für folgende Begriffe verbindlich die vorgegebene Übersetzung: ' . implode('; ', $pairs) . '.';
        }

        if (null !== $context && '' !== trim($context)) {
            $lines[] = 'Kontext des Textes (nur zur Orientierung, nicht mitübersetzen): ' . trim($context);
        }

        return implode("\n", $lines);
    }

    /**
     * Säubert die LLM-Rohantwort von typischem Beiwerk:
     *
     * 1. Reasoning-/Thinking-Block: Modelle wie qwen3 stellen der eigentlichen
     *    Antwort einen `<think>…</think>`-Block voran, in dem sie "laut
     *    nachdenken". Ollama liefert diesen Block über asText() mit aus — er darf
     *    NICHT in der Übersetzung landen. Wir entfernen einen führenden
     *    Think-Block. Modell-agnostisch: bei Modellen ohne Reasoning greift die
     *    Regex schlicht nicht.
     * 2. Umschließendes Anführungszeichen-Paar: nur wenn es das einzige Vorkommen
     *    ist (dann sicher Umschließung, kein echtes Zitat im Text).
     */
    private function clean(string $raw): string
    {
        $text = trim($raw);

        $stripped = preg_replace('/^\s*<think\b[^>]*>.*?<\/think>\s*/su', '', $text);
        if (null !== $stripped) {
            $text = trim($stripped);
        }

        if (
            strlen($text) >= 2
            && str_starts_with($text, '"')
            && str_ends_with($text, '"')
            && 2 === substr_count($text, '"')
        ) {
            $text = trim(substr($text, 1, -1));
        }

        return $text;
    }
}
