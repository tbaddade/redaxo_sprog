<?php

declare(strict_types=1);

namespace Sprog\Model;

use DateTimeImmutable;

/**
 * Value Object für einen Eintrag der Übersetzungs-Historie
 * (sprog_translation_history).
 *
 * Append-only Snapshot einer gespeicherten Wert-Version. Anders als
 * {@see ActivityEntry} (schlankes Audit-Log, nur Hashes) trägt die Historie
 * den VOLLEN Wert — genau das, was ein „Wiederherstellen" zurückholt.
 */
final readonly class TranslationHistoryEntry
{
    /**
     * @param int               $id            sprog_translation_history.id
     * @param int               $translationId FK → sprog_translation.id
     * @param int               $unitId        Komfort-Backref
     * @param int               $clangId       Komfort-Backref
     * @param string            $value         Voller Wert-Snapshot dieser Version
     * @param ?string           $valueHash     SHA-256 von $value (NULL bei leerem Wert)
     * @param string            $status        Status zum Snapshot-Zeitpunkt (Whitelist Sprog\Enum\Status)
     * @param ?string           $mtProvider    MT-Herkunft, falls maschinell erzeugt
     * @param ?float            $mtConfidence  gemeldete Confidence [0.0, 1.0]
     * @param string            $origin        'manual' | 'mt' | 'restore'
     * @param ?int              $userId        rex_user.id des Verursachers; NULL bei System-Aktionen
     * @param DateTimeImmutable $createdAt     Zeitpunkt der Version
     */
    public function __construct(
        public int $id,
        public int $translationId,
        public int $unitId,
        public int $clangId,
        public string $value,
        public ?string $valueHash,
        public string $status,
        public ?string $mtProvider,
        public ?float $mtConfidence,
        public string $origin,
        public ?int $userId,
        public DateTimeImmutable $createdAt,
    ) {}
}
