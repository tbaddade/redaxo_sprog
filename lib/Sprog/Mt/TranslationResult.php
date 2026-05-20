<?php

declare(strict_types=1);

namespace Sprog\Mt;

use InvalidArgumentException;

/**
 * Ergebnis eines MT-Provider-Aufrufs.
 *
 * Immutable; transportiert das übersetzte Text-Stück + Metadaten zurück
 * zum Caller. Wer das Ergebnis in eine Translation einbaut (TranslationService),
 * speichert Provider und Confidence in den entsprechenden Spalten von
 * sprog_translation.
 */
final readonly class TranslationResult
{
    /**
     * @param string              $text       der übersetzte Text
     * @param ?float              $confidence Optionaler Confidence-Score 0.0–1.0. NULL, wenn der Provider keine
     *                                        Schätzung liefert (z.B. DeepL gibt das nicht zurück).
     * @param string              $provider   provider-Name (siehe ProviderInterface::name())
     * @param array<string,mixed> $meta       Provider-spezifische Extras (z.B. detected source lang, used model,
     *                                        token-counts). Wird zu Diagnose-/Audit-Zwecken durchgereicht, nicht
     *                                        zur Anzeige gedacht.
     */
    public function __construct(
        public string $text,
        public ?float $confidence,
        public string $provider,
        public array $meta = [],
    ) {
        if (null !== $confidence && ($confidence < 0.0 || $confidence > 1.0)) {
            throw new InvalidArgumentException('confidence muss im Bereich [0.0, 1.0] liegen.');
        }
        if ('' === $provider) {
            throw new InvalidArgumentException('provider darf nicht leer sein.');
        }
    }
}
