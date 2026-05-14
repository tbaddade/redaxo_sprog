<?php

declare(strict_types=1);

namespace Sprog\Repository;

use DateTimeImmutable;
use rex;
use rex_sql;
use rex_sql_exception;
use RuntimeException;
use Sprog\Model\GlossaryEntry;

/**
 * Persistenz-Schicht für sprog_glossary.
 *
 * Schema-Reminder: UNIQUE-Index auf (source_clang_id, target_clang_id, source_term).
 * Verstösse landen als rex_sql_exception beim Caller — der Service-Layer fängt
 * das mit einer Benutzer-Meldung ab, statt es als 500er weiterzureichen.
 */
final class GlossaryRepository
{
    private const TABLE = 'sprog_glossary';

    /**
     * @throws rex_sql_exception
     */
    public function find(int $id): ?GlossaryEntry
    {
        $sql  = rex_sql::factory();
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
     * @return list<GlossaryEntry>
     *
     * @throws rex_sql_exception
     */
    public function findByPair(int $sourceClangId, int $targetClangId): array
    {
        $sql  = rex_sql::factory();
        $rows = $sql->getArray(
            'SELECT * FROM ' . $this->tableName() . '
             WHERE source_clang_id = :source AND target_clang_id = :target
             ORDER BY source_term ASC',
            ['source' => $sourceClangId, 'target' => $targetClangId],
        );

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * Lookup-Map für die MT-Pipeline: source_term => target_term.
     *
     * @return array<string, string>
     *
     * @throws rex_sql_exception
     */
    public function mapForPair(int $sourceClangId, int $targetClangId): array
    {
        $sql  = rex_sql::factory();
        $rows = $sql->getArray(
            'SELECT source_term, target_term FROM ' . $this->tableName() . '
             WHERE source_clang_id = :source AND target_clang_id = :target',
            ['source' => $sourceClangId, 'target' => $targetClangId],
        );

        $map = [];
        foreach ($rows as $row) {
            $key   = (string) $row['source_term'];
            $value = (string) $row['target_term'];
            if ('' === $key || '' === $value) {
                continue;
            }
            $map[$key] = $value;
        }

        return $map;
    }

    /**
     * @throws rex_sql_exception bei Insert mit Duplikat (UNIQUE-Constraint)
     */
    public function save(GlossaryEntry $entry): GlossaryEntry
    {
        $sql = rex_sql::factory();
        $sql->setTable($this->tableName());

        $sql->setValue('source_clang_id', $entry->sourceClangId);
        $sql->setValue('target_clang_id', $entry->targetClangId);
        $sql->setValue('source_term', $entry->sourceTerm);
        $sql->setValue('target_term', $entry->targetTerm);
        $sql->setValue('notes', $entry->notes);

        if (null === $entry->id) {
            $sql->addGlobalCreateFields();
            $sql->addGlobalUpdateFields();
            $sql->insert();
            $newId = (int) $sql->getLastId();

            return $this->find($newId) ?? throw new RuntimeException(
                'GlossaryEntry ' . $newId . ' nach Insert nicht auffindbar.',
            );
        }

        $sql->addGlobalUpdateFields();
        $sql->setWhere('id = :id', ['id' => $entry->id]);
        $sql->update();

        return $this->find($entry->id) ?? throw new RuntimeException(
            'GlossaryEntry ' . $entry->id . ' nach Update nicht auffindbar.',
        );
    }

    /**
     * @throws rex_sql_exception
     */
    public function delete(int $id): void
    {
        $sql = rex_sql::factory();
        $sql->setQuery(
            'DELETE FROM ' . $this->tableName() . ' WHERE id = :id',
            ['id' => $id],
        );
    }

    private function tableName(): string
    {
        return rex::getTable(self::TABLE);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): GlossaryEntry
    {
        return new GlossaryEntry(
            id:            (int) $row['id'],
            sourceClangId: (int) $row['source_clang_id'],
            targetClangId: (int) $row['target_clang_id'],
            sourceTerm:    (string) $row['source_term'],
            targetTerm:    (string) $row['target_term'],
            notes:         isset($row['notes']) && '' !== $row['notes'] ? (string) $row['notes'] : null,
            createdAt:     $this->toDateTime($row['createdate'] ?? null),
            updatedAt:     $this->toDateTime($row['updatedate'] ?? null),
        );
    }

    private function toDateTime(mixed $value): ?DateTimeImmutable
    {
        if (null === $value || '' === $value || '0000-00-00 00:00:00' === $value) {
            return null;
        }

        return new DateTimeImmutable((string) $value);
    }
}
