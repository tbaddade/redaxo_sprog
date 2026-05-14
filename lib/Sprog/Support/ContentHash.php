<?php

declare(strict_types=1);

namespace Sprog\Support;

/**
 * Zentraler Punkt für Inhalts-Hashing.
 *
 * SHA-256 als Default — überall im Schema als char(64) reserviert.
 * Falls wir den Algorithmus später wechseln, passiert das genau hier;
 * die Repositories und Services kennen den Algorithmus nicht direkt.
 *
 * Vergleiche IMMER über equals(), damit auch bei nicht-sensitiven Hashes
 * konsistent timing-safe verglichen wird (statt ===).
 */
final class ContentHash
{
    private const ALGO = 'sha256';

    public static function of(string $content): string
    {
        return hash(self::ALGO, $content);
    }

    public static function equals(?string $a, ?string $b): bool
    {
        if (null === $a || null === $b) {
            return false;
        }

        return hash_equals($a, $b);
    }
}
