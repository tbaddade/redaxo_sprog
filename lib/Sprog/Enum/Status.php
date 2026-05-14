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

    /**
     * Vollständige Whitelist erlaubter Folge-Status.
     *
     * Wird sowohl im TranslationService genutzt (Validierung über
     * `assertCanTransition`) als auch von Pages, um anzuzeigen, ob eine
     * Aktion grundsätzlich möglich ist. Single source of truth.
     *
     * Enthält auch destruktive Rückwege (z.B. Translated → Draft) und
     * System-induzierte Übergänge (Stale, Missing-Reset bei leerem Wert).
     * Forward-Workflow-Buttons im UI verwenden stattdessen `userActions()`.
     *
     * @return list<Status>
     */
    public function allowedNextStates(): array
    {
        return match ($this) {
            self::Missing      => [self::Draft, self::Translated],
            self::Draft        => [self::Translated, self::NeedsReview, self::Missing],
            self::Translated   => [self::NeedsReview, self::Approved, self::Stale, self::Draft],
            self::NeedsReview  => [self::Approved, self::Revise, self::Translated, self::Stale, self::Draft],
            self::Revise       => [self::Draft, self::Translated, self::NeedsReview],
            self::Approved     => [self::Stale, self::NeedsReview],
            self::Stale        => [self::Draft, self::Translated, self::NeedsReview],
        };
    }

    /**
     * UI-Subset von `allowedNextStates()`: nur die für den Reviewer-Alltag
     * relevanten Vorwärts-Aktionen. Reihenfolge nach Muster „nächster
     * natürlicher Workflow-Schritt zuerst, Alternativen danach".
     *
     * Die Reihenfolge bestimmt die Reihenfolge der Buttons im UI.
     *
     * Bewusst NICHT enthalten:
     * - Status::Missing als Ziel (Auto-Reset über leeren Wert, nicht User-Aktion)
     * - Status::Stale als Ziel (System-Status, wird per Hash-Detection gesetzt)
     * - destruktive Rückwege auf Draft (Wert geht nicht verloren, aber das
     *   Konzept „Reviewer wirft auf Draft zurück" hat seinen eigenen Status
     *   `Revise`)
     *
     * @return list<Status>
     */
    public function userActions(): array
    {
        return match ($this) {
            self::Missing      => [],
            self::Draft        => [self::Translated, self::NeedsReview],
            self::Translated   => [self::NeedsReview, self::Approved],
            self::NeedsReview  => [self::Revise, self::Approved],
            self::Revise       => [self::Translated, self::NeedsReview],
            self::Approved     => [self::NeedsReview],
            self::Stale        => [self::Translated, self::NeedsReview],
        };
    }

    /**
     * Prüft ohne Exception, ob der Übergang in der Whitelist liegt.
     * Idempotenz (from == to) gilt als erlaubt.
     */
    public function canTransitionTo(Status $to): bool
    {
        if ($this === $to) {
            return true;
        }

        return in_array($to, $this->allowedNextStates(), true);
    }
}
