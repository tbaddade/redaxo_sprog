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
     * @return list<GlossaryEntry>
     */
    public function findByPair(int $sourceClangId, int $targetClangId): array
    {
        $sql = rex_sql::factory();
        $rows = $sql->getArray(
            'SELECT * FROM ' . $this->tableName() . '
             WHERE source_clang_id = :source AND target_clang_id = :target
             ORDER BY source_term ASC',
            ['source' => $sourceClangId, 'target' => $targetClangId],
        );

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * Liste über alle Sprachpaare, optional gefiltert. Basis der Inbox-artigen
     * Glossar-Ansicht: zeigt per Default alles, Quelle/Ziel/Suche grenzen ein.
     *
     * $allowedClangIds ist das Perm-Gate — nur Einträge, deren BEIDE Sprachen
     * der User sehen darf. Leere Liste ⇒ kein Ergebnis (kein All-Pass).
     *
     * @param list<int> $allowedClangIds
     * @throws rex_sql_exception
     * @return list<GlossaryEntry>
     */
    public function findAll(
        array $allowedClangIds,
        ?int $sourceClangId = null,
        ?int $targetClangId = null,
        ?string $search = null,
    ): array {
        if ([] === $allowedClangIds) {
            return [];
        }

        $conditions = [];
        $params = [];

        // Perm-Gate als IN-Liste mit dynamischen Platzhaltern (PDO-gebunden).
        $placeholders = [];
        foreach (array_values($allowedClangIds) as $i => $cid) {
            $placeholders[] = ':allowed' . $i;
            $params['allowed' . $i] = $cid;
        }
        $inList = implode(', ', $placeholders);
        $conditions[] = 'source_clang_id IN (' . $inList . ')';
        // target 0 = „Alle Sprachen" — nicht an eine (evtl. gesperrte) Clang
        // gebunden, daher zusätzlich zur Perm-Liste zugelassen.
        $conditions[] = '(target_clang_id IN (' . $inList . ') OR target_clang_id = 0)';

        if (null !== $sourceClangId) {
            $conditions[] = 'source_clang_id = :source';
            $params['source'] = $sourceClangId;
        }
        if (null !== $targetClangId) {
            $conditions[] = 'target_clang_id = :target';
            $params['target'] = $targetClangId;
        }
        if (null !== $search && '' !== $search) {
            // LIKE-Sonderzeichen im User-Input neutralisieren, sonst wirken
            // % und _ als Wildcards. MySQL-Default-Escape-Char ist Backslash.
            $conditions[] = '(source_term LIKE :search OR target_term LIKE :search OR notes LIKE :search)';
            $params['search'] = '%' . $this->escapeLike($search) . '%';
        }

        $sql = rex_sql::factory();
        $rows = $sql->getArray(
            'SELECT * FROM ' . $this->tableName() . '
             WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY source_clang_id ASC, target_clang_id ASC, source_term ASC',
            $params,
        );

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * Lookup-Map für die MT-Pipeline: source_term => target_term.
     *
     * @throws rex_sql_exception
     * @return array<string, string>
     */
    public function mapForPair(int $sourceClangId, int $targetClangId): array
    {
        $sql = rex_sql::factory();
        // Einträge für dieses Sprachpaar UND „Alle Sprachen" (target 0).
        // ORDER BY target_clang_id ASC: die 0-Einträge (Alle) gehen zuerst in die
        // Map, ein spezifischer Eintrag (konkrete Zielsprache) überschreibt sie.
        $rows = $sql->getArray(
            'SELECT source_term, target_term FROM ' . $this->tableName() . '
             WHERE source_clang_id = :source AND target_clang_id IN (:target, 0)
             ORDER BY target_clang_id ASC',
            ['source' => $sourceClangId, 'target' => $targetClangId],
        );

        $map = [];
        foreach ($rows as $row) {
            $key = (string) $row['source_term'];
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

            return $this->find($newId) ?? throw new RuntimeException('GlossaryEntry ' . $newId . ' nach Insert nicht auffindbar.');
        }

        $sql->addGlobalUpdateFields();
        $sql->setWhere('id = :id', ['id' => $entry->id]);
        $sql->update();

        return $this->find($entry->id) ?? throw new RuntimeException('GlossaryEntry ' . $entry->id . ' nach Update nicht auffindbar.');
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
     * Maskiert LIKE-Metazeichen, damit User-Eingaben wörtlich gesucht werden.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): GlossaryEntry
    {
        return new GlossaryEntry(
            id: (int) $row['id'],
            sourceClangId: (int) $row['source_clang_id'],
            targetClangId: (int) $row['target_clang_id'],
            sourceTerm: (string) $row['source_term'],
            targetTerm: (string) $row['target_term'],
            notes: isset($row['notes']) && '' !== $row['notes'] ? (string) $row['notes'] : null,
            createdAt: $this->toDateTime($row['createdate'] ?? null),
            updatedAt: $this->toDateTime($row['updatedate'] ?? null),
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
