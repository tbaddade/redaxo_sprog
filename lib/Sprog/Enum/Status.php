<?php

declare(strict_types=1);

namespace Sprog\Enum;

use function in_array;

/**
 * Workflow-Status einer Übersetzung.
 *
 * Erlaubte Übergänge (rollen-agnostische Whitelist; WER welchen Übergang
 * auslösen darf, entscheidet der WorkflowService anhand der Rollen):
 *   missing      → draft
 *   draft        → needs_review (》Zur Prüfung《), approved (Direkt-Freigabe)
 *   needs_review → approved (》Freigeben《), revise (》Zurückgeben《)
 *   revise       → draft (Nacharbeit), approved (Direkt-Freigabe)
 *   approved     → revise (》Zurückgeben《 auf Freigegebenem), stale (System),
 *                  draft (inhaltlicher Edit an Freigegebenem — Siegel gilt nicht mehr)
 *   stale        → draft, approved
 *
 * Rollen (WorkflowService): `sprog[translator]` reicht ein (》Zur Prüfung《),
 * `sprog[reviewer]` gibt frei / gibt zurück. Wer beides darf (oder Admin),
 * gibt direkt frei (kein Zwischenschritt). `needs_review` ist damit wieder
 * ein aktiver Nutzer-Status (nicht mehr rein system-gesetzt).
 *
 * Persistiert wird der string-Wert in sprog_translation.status (varchar(32)).
 */
enum Status: string
{
    /** Quell-Wert existiert, Übersetzung fehlt komplett. */
    case Missing = 'missing';

    /** Übersetzung existiert, ist aber noch nicht zur Prüfung eingereicht. */
    case Draft = 'draft';

    /** Übersetzung eingereicht, wartet auf die Prüfung durch einen Reviewer. */
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
        return self::Approved === $this;
    }

    /**
     * Status, die anzeigen, dass an dieser Übersetzung noch Arbeit ansteht.
     * Fertig ist ausschließlich `approved`.
     */
    public function isOpen(): bool
    {
        return match ($this) {
            self::Missing, self::Draft, self::NeedsReview, self::Revise, self::Stale => true,
            self::Approved => false,
        };
    }

    /**
     * Vollständige Whitelist erlaubter Folge-Status (rollen-agnostisch).
     *
     * Wird im TranslationService zur Validierung genutzt (`assertCanTransition`).
     * Welche dieser Übergänge einem konkreten Nutzer als Button angeboten und
     * serverseitig autorisiert werden, entscheidet der WorkflowService anhand
     * der Rollen (`sprog[translator]` / `sprog[reviewer]` / Admin).
     *
     * Enthält Rückwege (Revise → Draft) und System-Übergänge (Stale). Der
     * Reset auf `missing` bei leerem Wert umgeht diese Whitelist bewusst
     * (siehe TranslationService::updateValue).
     *
     * @return list<Status>
     */
    public function allowedNextStates(): array
    {
        return match ($this) {
            self::Missing => [self::Draft],
            self::Draft => [self::NeedsReview, self::Approved],
            self::NeedsReview => [self::Approved, self::Revise],
            self::Revise => [self::Draft, self::Approved],
            self::Approved => [self::Revise, self::Stale, self::Draft],
            self::Stale => [self::Draft, self::Approved],
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
