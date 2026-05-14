<?php

declare(strict_types=1);

namespace Sprog\Enum;

/**
 * Workflow-Status einer Übersetzung.
 *
 * Übergänge sind erlaubt:
 *   missing       → draft, translated
 *   draft         → translated, needs_review, missing
 *   translated    → needs_review, approved, stale
 *   needs_review  → approved, revise, stale
 *   approved      → stale, needs_review
 *   revise        → draft, translated, needs_review
 *   stale         → draft, translated, needs_review
 *
 * Die Übergangslogik selbst lebt im TranslationService; das Enum
 * beschreibt nur die Werte. Persistiert wird der string-Wert in
 * sprog_translation.status (varchar(32)).
 */
enum Status: string
{
    /** Quell-Wert existiert, Übersetzung fehlt komplett. */
    case Missing = 'missing';

    /** Übersetzung existiert, ist aber noch nicht final (z.B. MT-Vorschlag). */
    case Draft = 'draft';

    /** Übersetzung vom Übersetzer abgeschlossen, wartet ggf. auf Review. */
    case Translated = 'translated';

    /** Wurde markiert, dass ein Reviewer drüberschauen soll. */
    case NeedsReview = 'needs_review';

    /** Reviewer hat Änderungen angefordert; Übersetzer muss überarbeiten. */
    case Revise = 'revise';

    /** Vom Reviewer freigegeben. Final. */
    case Approved = 'approved';

    /** Quell-Wert hat sich seit der Übersetzung geändert; Re-Übersetzung nötig. */
    case Stale = 'stale';

    /**
     * Alle Status-Werte als Strings.
     * Praktisch für Selectboxen, Filter und Schema-Synchronisation.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c) => $c->value, self::cases());
    }

    /**
     * Endgültige Status, an denen normaler Workflow nicht mehr ansetzt.
     */
    public function isFinal(): bool
    {
        return $this === self::Approved;
    }

    /**
     * Status, die anzeigen, dass an dieser Übersetzung noch Arbeit ansteht.
     */
    public function isOpen(): bool
    {
        return match ($this) {
            self::Missing, self::Draft, self::NeedsReview, self::Revise, self::Stale => true,
            self::Translated, self::Approved => false,
        };
    }
}
