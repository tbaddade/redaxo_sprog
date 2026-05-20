<?php

declare(strict_types=1);

namespace Sprog\Repository;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use rex;
use rex_sql;
use rex_sql_exception;
use RuntimeException;
use Sprog\Model\ActivityEntry;

use function is_array;

use const JSON_THROW_ON_ERROR;

/**
 * Persistenz-Schicht für sprog_activity (append-only Audit-Log).
 *
 * Bewusst kein update(), kein delete(id). Activity-Einträge sind
 * immutabel — wenn Bereinigung nötig wird (Retention nach n Tagen),
 * passiert das über eine eigene, klar benannte Cleanup-Methode.
 */
final class ActivityRepository
{
    private const TABLE = 'sprog_activity';

    /**
     * @param array<string,mixed> $payload
     *
     * @throws JsonException
     * @throws rex_sql_exception
     */
    public function insert(
        ?int $unitId,
        ?int $translationId,
        ?int $userId,
        string $action,
        array $payload,
    ): int {
        $sql = rex_sql::factory();
        $sql->setTable($this->tableName());
        $sql->setValue('unit_id', $unitId);
        $sql->setValue('translation_id', $translationId);
        $sql->setValue('user_id', $userId);
        $sql->setValue('action', $action);
        $sql->setValue('payload', [] === $payload ? null : json_encode($payload, JSON_THROW_ON_ERROR));
        $sql->setValue('created_at', date('Y-m-d H:i:s'));
        $sql->insert();

        return (int) $sql->getLastId();
    }

    /**
     * @throws rex_sql_exception
     * @throws JsonException
     */
    public function find(int $id): ?ActivityEntry
    {
        $sql = rex_sql::factory();
        $rows = $sql->getArray(
            'SELECT * FROM ' . $this->tableName() . ' WHERE id = :id LIMIT 1',
            ['id' => $id],
        );

        if ([] === $rows) {
            return null;
        }

        return $this->hydrate($rows[0]);
    }

    /**
     * @throws rex_sql_exception
     * @throws JsonException
     * @return list<ActivityEntry>
     */
    public function recentForUnit(int $unitId, int $limit, int $offset = 0): array
    {
        return $this->recentBy('unit_id = :unit_id', ['unit_id' => $unitId], $limit, $offset);
    }

    /**
     * @throws rex_sql_exception
     * @throws JsonException
     * @return list<ActivityEntry>
     */
    public function recentForTranslation(int $translationId, int $limit, int $offset = 0): array
    {
        return $this->recentBy(
            'translation_id = :translation_id',
            ['translation_id' => $translationId],
            $limit,
            $offset,
        );
    }

    /**
     * Bereinigung alter Einträge — separater, deutlich benannter Pfad,
     * damit unbeabsichtigtes Löschen nicht durch ein generisches delete()
     * leicht passiert.
     *
     * @throws rex_sql_exception
     */
    public function deleteOlderThan(DateTimeImmutable $threshold): int
    {
        $sql = rex_sql::factory();
        $sql->setQuery(
            'DELETE FROM ' . $this->tableName() . ' WHERE created_at < :threshold',
            ['threshold' => $threshold->format('Y-m-d H:i:s')],
        );

        return $sql->getRows();
    }

    /**
     * @param array<string, mixed> $params
     *
     * @throws rex_sql_exception
     * @throws JsonException
     * @return list<ActivityEntry>
     */
    private function recentBy(string $where, array $params, int $limit, int $offset): array
    {
        if ($limit <= 0 || $offset < 0) {
            throw new InvalidArgumentException('limit muss > 0 und offset >= 0 sein.');
        }

        $sql = rex_sql::factory();
        $rows = $sql->getArray(
            'SELECT * FROM ' . $this->tableName() . '
             WHERE ' . $where . '
             ORDER BY created_at DESC, id DESC
             LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset,
            $params,
        );

        return array_map($this->hydrate(...), $rows);
    }

    private function tableName(): string
    {
        return rex::getTable(self::TABLE);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @throws JsonException
     */
    private function hydrate(array $row): ActivityEntry
    {
        $payload = [];
        if (isset($row['payload']) && '' !== $row['payload']) {
            $decoded = json_decode((string) $row['payload'], true, 32, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        if (!isset($row['created_at'])) {
            throw new RuntimeException('Activity ' . (string) ($row['id'] ?? '?') . ' ohne created_at — Schema verletzt.');
        }

        return new ActivityEntry(
            id: (int) $row['id'],
            unitId: isset($row['unit_id']) ? (int) $row['unit_id'] : null,
            translationId: isset($row['translation_id']) ? (int) $row['translation_id'] : null,
            userId: isset($row['user_id']) ? (int) $row['user_id'] : null,
            action: (string) $row['action'],
            payload: $payload,
            createdAt: new DateTimeImmutable((string) $row['created_at']),
        );
    }
}
