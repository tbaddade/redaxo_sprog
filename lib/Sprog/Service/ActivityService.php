<?php

declare(strict_types=1);

namespace Sprog\Service;

use InvalidArgumentException;
use Sprog\Enum\Status;
use Sprog\Repository\ActivityRepository;

use function strlen;

/**
 * Audit-Log-Schreiber.
 *
 * Stellt typsichere Log-Methoden für die häufigsten Aktionen bereit
 * (Translation-Update, Status-Übergang, MT-Lauf, Quell-Änderung) und
 * einen generischen log()-Pfad für alles andere.
 *
 * Wichtig — bewusste Datenarmut:
 *   Wir loggen Metadaten und Hashes, nicht den Klartext alter/neuer
 *   Übersetzungen. Wer die Werte selbst nachvollziehen will, kann das
 *   über die Revision-Historie der Translation (kommt später) tun.
 *   Audit-Log ist "wer hat wann was an welcher Stelle gemacht", nicht
 *   ein zweiter Speicher für Inhalte.
 */
final class ActivityService
{
    public const ACTION_UNIT_CREATED = 'unit.created';
    public const ACTION_UNIT_DELETED = 'unit.deleted';
    public const ACTION_SOURCE_CHANGED = 'source.changed';
    public const ACTION_TRANSLATION_CREATED = 'translation.created';
    public const ACTION_TRANSLATION_UPDATED = 'translation.updated';
    public const ACTION_TRANSLATION_DELETED = 'translation.deleted';
    public const ACTION_STATUS_TRANSITION = 'status.transition';
    public const ACTION_MT_DRAFT_CREATED = 'mt.draft_created';
    public const ACTION_BULK = 'bulk';

    /**
     * action darf nur a-z0-9_ und Punkte als Trenner enthalten, max 32 Zeichen.
     * Verhindert Log-Verwirrung durch Newlines / Sonderzeichen und stellt
     * sicher, dass die varchar(32)-Spalte nicht überläuft.
     */
    private const ACTION_PATTERN = '/^[a-z0-9_]+(?:\.[a-z0-9_]+)*$/';

    public function __construct(
        private readonly ActivityRepository $repository,
    ) {}

    public static function create(): self
    {
        return new self(new ActivityRepository());
    }

    /**
     * Generischer Pfad. payload sollte nur strukturelle Werte enthalten
     * (Status, Hashes, IDs, Counts) — keine personenbezogenen oder
     * geheimen Inhalte; siehe Klassen-Doc.
     *
     * @param array<string,mixed> $payload
     */
    public function log(
        string $action,
        ?int $unitId,
        ?int $translationId,
        ?int $userId,
        array $payload = [],
    ): int {
        $this->assertValidAction($action);

        return $this->repository->insert($unitId, $translationId, $userId, $action, $payload);
    }

    public function logUnitCreated(int $unitId, ?int $userId, string $namespace, string $unitKey): int
    {
        return $this->log(self::ACTION_UNIT_CREATED, $unitId, null, $userId, [
            'namespace' => $namespace,
            'unit_key' => $unitKey,
        ]);
    }

    public function logSourceChanged(int $unitId, ?int $userId, ?string $oldHash, ?string $newHash, int $staleCount): int
    {
        return $this->log(self::ACTION_SOURCE_CHANGED, $unitId, null, $userId, [
            'old_hash' => $oldHash,
            'new_hash' => $newHash,
            'stale_count' => $staleCount,
        ]);
    }

    public function logTranslationUpdated(
        int $unitId,
        int $translationId,
        ?int $userId,
        Status $oldStatus,
        Status $newStatus,
        ?string $oldValueHash,
        ?string $newValueHash,
    ): int {
        return $this->log(self::ACTION_TRANSLATION_UPDATED, $unitId, $translationId, $userId, [
            'old_status' => $oldStatus->value,
            'new_status' => $newStatus->value,
            'old_value_hash' => $oldValueHash,
            'new_value_hash' => $newValueHash,
        ]);
    }

    public function logStatusTransition(
        int $unitId,
        int $translationId,
        ?int $userId,
        Status $from,
        Status $to,
    ): int {
        return $this->log(self::ACTION_STATUS_TRANSITION, $unitId, $translationId, $userId, [
            'from' => $from->value,
            'to' => $to->value,
        ]);
    }

    public function logMtDraftCreated(
        int $unitId,
        int $translationId,
        ?int $userId,
        string $provider,
        ?float $confidence,
    ): int {
        return $this->log(self::ACTION_MT_DRAFT_CREATED, $unitId, $translationId, $userId, [
            'provider' => $provider,
            'confidence' => $confidence,
        ]);
    }

    private function assertValidAction(string $action): void
    {
        if ('' === $action || strlen($action) > 32 || 1 !== preg_match(self::ACTION_PATTERN, $action)) {
            throw new InvalidArgumentException('Ungültiger Action-Name. Erlaubt: a-z, 0-9, "_" und "." als Trenner, max. 32 Zeichen.');
        }
    }
}
