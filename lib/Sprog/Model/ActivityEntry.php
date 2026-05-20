<?php

declare(strict_types=1);

namespace Sprog\Model;

use DateTimeImmutable;

/**
 * Value Object für einen Eintrag im Audit-Log (sprog_activity).
 *
 * Append-only: einmal geschrieben werden Aktivitäts-Einträge nicht
 * mehr verändert. Daher gibt es kein updatedAt; created_at ist
 * Pflicht, alle anderen Felder können je nach Aktion fehlen.
 */
final readonly class ActivityEntry
{
    /**
     * @param int                 $id            sprog_activity.id
     * @param ?int                $unitId        Verknüpfte Unit, falls die Aktion eine betraf
     * @param ?int                $translationId Verknüpfte Translation, falls die Aktion eine betraf
     * @param ?int                $userId        rex_user.id; NULL bei System-Aktionen
     * @param string              $action        Maschinenlesbarer Aktionstyp, z.B. 'translation.updated'
     * @param array<string,mixed> $payload       Aktions-Details, JSON-decoded
     * @param DateTimeImmutable   $createdAt     Zeitpunkt der Aktion
     */
    public function __construct(
        public int $id,
        public ?int $unitId,
        public ?int $translationId,
        public ?int $userId,
        public string $action,
        public array $payload,
        public DateTimeImmutable $createdAt,
    ) {}
}
