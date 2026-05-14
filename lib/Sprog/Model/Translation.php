<?php

declare(strict_types=1);

namespace Sprog\Model;

use DateTimeImmutable;
use Sprog\Enum\Status;

/**
 * Value Object für eine konkrete Übersetzung (sprog_translation).
 *
 * Pro (Unit, clang_id) genau eine Instanz. value_hash und
 * source_hash_at_translation werden vom Service-Layer berechnet,
 * der die Hashing-Strategie kapselt.
 *
 * Immutable. Bei Mutationen produziert der Service-Layer eine neue Instanz.
 */
final readonly class Translation
{
    /**
     * @param ?int                $id                       NULL, wenn noch nicht in DB
     * @param int                 $unitId                   sprog_unit.id
     * @param int                 $clangId                  rex_clang.id
     * @param string              $value                    Der übersetzte Text (kann leer sein bei Status=missing)
     * @param ?string             $valueHash                SHA-256 von $value, hex; NULL bei Status=missing
     * @param ?string             $sourceHashAtTranslation  SHA-256 des Quell-Wertes zum Zeitpunkt der Übersetzung
     * @param Status              $status                   Workflow-Status
     * @param ?string             $mtProvider               Wenn von MT erzeugt: Provider-Kennung
     * @param ?float              $mtConfidence             MT-Confidence im Bereich 0.0 - 1.0
     * @param ?int                $translatorId             rex_user.id des Übersetzers
     * @param ?int                $reviewerId               rex_user.id des Reviewers
     * @param int                 $revision                 Optimistic-Locking-Zähler
     * @param ?DateTimeImmutable  $createdAt                NULL, wenn noch nicht in DB
     * @param ?DateTimeImmutable  $updatedAt                NULL, wenn noch nicht in DB
     */
    public function __construct(
        public ?int $id,
        public int $unitId,
        public int $clangId,
        public string $value,
        public ?string $valueHash,
        public ?string $sourceHashAtTranslation,
        public Status $status,
        public ?string $mtProvider = null,
        public ?float $mtConfidence = null,
        public ?int $translatorId = null,
        public ?int $reviewerId = null,
        public int $revision = 0,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $updatedAt = null,
    ) {
    }

    /**
     * True, wenn der Quell-Wert sich seit der Übersetzung geändert hat.
     * Verglichen wird mit dem aktuellen source_hash der zugehörigen Unit.
     */
    public function isStaleAgainst(?string $currentSourceHash): bool
    {
        if (null === $currentSourceHash || null === $this->sourceHashAtTranslation) {
            return false;
        }

        return !hash_equals($this->sourceHashAtTranslation, $currentSourceHash);
    }
}
