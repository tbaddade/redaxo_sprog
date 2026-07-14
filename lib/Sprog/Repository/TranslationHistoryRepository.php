<?php

declare(strict_types=1);

namespace Sprog\Repository;

use DateTimeImmutable;
use InvalidArgumentException;
use rex;
use rex_sql;
use rex_sql_exception;
use RuntimeException;
use Sprog\Model\TranslationHistoryEntry;

/**
 * Persistenz-Schicht für sprog_translation_history (append-only Versions-Log).
 *
 * Wie {@see ActivityRepository} bewusst ohne update() — jeder Eintrag ist ein
 * unveränderlicher Snapshot. Für Retention gibt es pruneKeepingLast() statt
 * eines generischen delete().
 */
final class TranslationHistoryRepository
{
    private const TABLE = 'sprog_translation_history';

    /**
     * Hängt einen Wert-Snapshot an. $createdAt erlaubt es, einen Baseline-
     * Eintrag mit dem tatsächlichen Alt-Zeitstempel zu setzen; NULL → DB-NOW()
     * (konsistente DB-Zeitzone, analog ActivityRepository).
     *
     * @throws rex_sql_exception
     */
    public function insert(
        int $translationId,
        int $unitId,
        int $clangId,
        string $value,
        ?string $valueHash,
        string $status,
        ?string $mtProvider,
        ?float $mtConfidence,
        string $origin,
        ?int $userId,
        ?DateTimeImmutable $createdAt = null,
    ): int {
        $sql = rex_sql::factory();
        $sql->setTable($this->tableName());
        $sql->setValue('translation_id', $translationId);
        $sql->setValue('unit_id', $unitId);
        $sql->setValue('clang_id', $clangId);
        $sql->setValue('value', $value);
        $sql->setValue('value_hash', $valueHash);
        $sql->setValue('status', $status);
        $sql->setValue('mt_provider', $mtProvider);
        $sql->setValue('mt_confidence', $mtConfidence);
        $sql->setValue('origin', $origin);
        $sql->setValue('user_id', $userId);
        if (null === $createdAt) {
            $sql->setRawValue('created_at', 'NOW()');
        } else {
            $sql->setValue('created_at', $createdAt->format('Y-m-d H:i:s'));
        }
        $sql->insert();

        return (int) $sql->getLastId();
    }

    /**
     * @throws rex_sql_exception
     */
    public function find(int $id): ?TranslationHistoryEntry
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
     * Günstiger Existenz-Check (für den Baseline-Seed): gibt es schon einen
     * Historie-Eintrag zu dieser Übersetzung?
     *
     * @throws rex_sql_exception
     */
    public function existsForTranslation(int $translationId): bool
    {
        $sql = rex_sql::factory();
        $rows = $sql->getArray(
            'SELECT 1 FROM ' . $this->tableName() . ' WHERE translation_id = :tid LIMIT 1',
            ['tid' => $translationId],
        );

        return [] !== $rows;
    }

    /**
     * Versionen einer Übersetzung, neueste zuerst.
     *
     * @throws rex_sql_exception
     * @return list<TranslationHistoryEntry>
     */
    public function listForTranslation(int $translationId, int $limit): array
    {
        if ($limit <= 0) {
            throw new InvalidArgumentException('limit muss > 0 sein.');
        }

        $sql = rex_sql::factory();
        $rows = $sql->getArray(
            'SELECT * FROM ' . $this->tableName() . '
             WHERE translation_id = :tid
             ORDER BY created_at DESC, id DESC
             LIMIT ' . (int) $limit,
            ['tid' => $translationId],
        );

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * Retention: behält die neuesten $keep Versionen einer Übersetzung, löscht
     * ältere. Läuft nach jedem Insert, hält die Log-Tabelle beschränkt.
     *
     * @throws rex_sql_exception
     */
    public function pruneKeepingLast(int $translationId, int $keep): int
    {
        if ($keep < 1) {
            $keep = 1;
        }

        $table = $this->tableName();
        $sql = rex_sql::factory();
        // Derived-Table-Wrapper um die Subquery: MySQL erlaubt sonst kein
        // LIMIT in einer IN-Subquery, die dieselbe Tabelle referenziert wie
        // das DELETE. $keep ist ein gecasteter int (keine Injektion).
        $sql->setQuery(
            'DELETE FROM ' . $table . '
             WHERE translation_id = :tid
               AND id NOT IN (
                 SELECT id FROM (
                   SELECT id FROM ' . $table . '
                   WHERE translation_id = :tid2
                   ORDER BY created_at DESC, id DESC
                   LIMIT ' . (int) $keep . '
                 ) AS keep_ids
               )',
            ['tid' => $translationId, 'tid2' => $translationId],
        );

        return $sql->getRows();
    }

    private function tableName(): string
    {
        return rex::getTable(self::TABLE);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): TranslationHistoryEntry
    {
        if (!isset($row['created_at'])) {
            throw new RuntimeException('History ' . (string) ($row['id'] ?? '?') . ' ohne created_at — Schema verletzt.');
        }

        return new TranslationHistoryEntry(
            id: (int) $row['id'],
            translationId: (int) $row['translation_id'],
            unitId: (int) $row['unit_id'],
            clangId: (int) $row['clang_id'],
            value: (string) ($row['value'] ?? ''),
            valueHash: isset($row['value_hash']) && '' !== $row['value_hash'] ? (string) $row['value_hash'] : null,
            status: (string) $row['status'],
            mtProvider: isset($row['mt_provider']) && '' !== $row['mt_provider'] ? (string) $row['mt_provider'] : null,
            mtConfidence: isset($row['mt_confidence']) ? (float) $row['mt_confidence'] : null,
            origin: (string) ($row['origin'] ?? 'manual'),
            userId: isset($row['user_id']) ? (int) $row['user_id'] : null,
            createdAt: new DateTimeImmutable((string) $row['created_at']),
        );
    }
}
