<?php

declare(strict_types=1);

namespace Sprog\Repository;

use DateTimeImmutable;
use JsonException;
use rex;
use rex_sql;
use rex_sql_exception;
use RuntimeException;
use Sprog\Enum\SourceType;
use Sprog\Model\Unit;

use function is_array;
use function is_string;

use const JSON_THROW_ON_ERROR;

/**
 * Persistenz-Schicht für sprog_unit.
 *
 * Sämtliche Schreib- und Lesevorgänge gehen über diese Klasse —
 * der Service-Layer kennt keine SQL-Details. Alle Queries sind
 * prepared, IDs sind int-typisiert, Enums werden an der Grenze
 * (DB-Wert → Enum) per Status::from() validiert.
 */
final class UnitRepository
{
    private const TABLE = 'sprog_unit';

    /**
     * @throws rex_sql_exception
     * @throws JsonException
     */
    public function find(int $id): ?Unit
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
     * Bulk-Loader: mehrere Units in einem Query.
     * Wird vom Inbox-Akkordeon genutzt, um pro Page nicht N+1 Selects abzusetzen.
     *
     * @param list<int> $ids
     *
     * @throws rex_sql_exception
     * @throws JsonException
     * @return array<int, Unit>  id → Unit
     */
    public function findMany(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $ints = array_map(static fn ($id) => (int) $id, $ids);
        $inClause = implode(',', $ints);

        $sql = rex_sql::factory();
        $rows = $sql->getArray(
            'SELECT * FROM ' . $this->tableName() . ' WHERE id IN (' . $inClause . ')',
        );

        $byId = [];
        foreach ($rows as $row) {
            $unit = $this->hydrate($row);
            $byId[(int) $unit->id] = $unit;
        }

        return $byId;
    }

    /**
     * Liefert alle distinct context-Werte, die in der Unit-Tabelle vorkommen
     * (ohne leeren String). Wird für das Modal-Datalist als Vorschlagsliste
     * genutzt, damit der User beim Anlegen neuer Units bereits bekannte
     * Bereiche schnell auswählen kann.
     *
     * @throws rex_sql_exception
     * @return list<string>
     */
    public function findAllContexts(): array
    {
        $sql = rex_sql::factory();
        try {
            $rows = $sql->getArray(
                'SELECT DISTINCT context FROM ' . $this->tableName() . "
                 WHERE context <> ''
                 ORDER BY context ASC",
            );
        } catch (rex_sql_exception) {
            // context-Spalte existiert noch nicht (kein Re-Install gelaufen).
            // Frontend rendert dann ein leeres Datalist — vertretbar.
            return [];
        }

        return array_values(array_map(static fn (array $r) => (string) $r['context'], $rows));
    }

    /**
     * Nachschlagen über das natürliche Schlüsseltripel (namespace, context, unit_key).
     * Spiegelt den UNIQUE-Index der sprog_unit-Tabelle (V2Schema).
     *
     * @throws rex_sql_exception
     * @throws JsonException
     */
    public function findByKey(string $namespace, string $unitKey, string $context = ''): ?Unit
    {
        $sql = rex_sql::factory();
        $rows = $sql->getArray(
            'SELECT * FROM ' . $this->tableName() . '
             WHERE namespace = :namespace AND context = :context AND unit_key = :unit_key
             LIMIT 1',
            [
                'namespace' => $namespace,
                'context' => $context,
                'unit_key' => $unitKey,
            ],
        );

        if ([] === $rows) {
            return null;
        }

        return $this->hydrate($rows[0]);
    }

    /**
     * Alle Units, die auf eine bestimmte REDAXO-Entität verweisen.
     * Praktisch z.B. für "alle Übersetzungen zu Artikel 42 finden".
     *
     * @throws rex_sql_exception
     * @throws JsonException
     * @return list<Unit>
     */
    public function findBySource(SourceType $type, string $ref): array
    {
        $sql = rex_sql::factory();
        $rows = $sql->getArray(
            'SELECT * FROM ' . $this->tableName() . '
             WHERE source_type = :type AND source_ref = :ref
             ORDER BY id',
            ['type' => $type->value, 'ref' => $ref],
        );

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * Anlegen oder Aktualisieren — unterscheidet anhand von $unit->id.
     *
     * Achtung: ein leerer string als sourceRef wird nicht zu NULL — das
     * ist Aufgabe des Service-Layers, der die fachliche Entscheidung trifft.
     *
     * @throws rex_sql_exception
     * @throws JsonException
     */
    public function save(Unit $unit): Unit
    {
        $sql = rex_sql::factory();
        $sql->setTable($this->tableName());

        $sql->setValue('namespace', $unit->namespace);
        $sql->setValue('context', $unit->context);
        $sql->setValue('unit_key', $unit->unitKey);
        $sql->setValue('source_type', $unit->sourceType?->value);
        $sql->setValue('source_ref', $unit->sourceRef);
        $sql->setValue('source_hash', $unit->sourceHash);
        $sql->setValue('tags', json_encode($unit->tags, JSON_THROW_ON_ERROR));
        $sql->setValue('notes', $unit->notes);

        if (null === $unit->id) {
            $sql->addGlobalCreateFields();
            $sql->addGlobalUpdateFields();
            $sql->insert();
            $newId = (int) $sql->getLastId();

            return $this->find($newId) ?? throw new RuntimeException('Unit ' . $newId . ' nach Insert nicht auffindbar.');
        }

        $sql->addGlobalUpdateFields();
        $sql->setWhere('id = :id', ['id' => $unit->id]);
        $sql->update();

        return $this->find($unit->id) ?? throw new RuntimeException('Unit ' . $unit->id . ' nach Update nicht auffindbar.');
    }

    /**
     * Nur den Source-Hash aktualisieren — günstigerer Pfad für die
     * Stale-Detection nach Quell-Sprach-Edits, ohne den Rest zu touchen.
     *
     * @throws rex_sql_exception
     */
    public function updateSourceHash(int $id, ?string $hash): void
    {
        $sql = rex_sql::factory();
        $sql->setTable($this->tableName());
        $sql->setValue('source_hash', $hash);
        $sql->addGlobalUpdateFields();
        $sql->setWhere('id = :id', ['id' => $id]);
        $sql->update();
    }

    /**
     * Löscht ausschließlich die Unit-Row. Caller-Verantwortung: zugehörige
     * Translation-Rows separat über TranslationRepository::deleteByUnit($id)
     * löschen — REDAXO bietet keine DB-Cascades, ein Cleanup-Schritt im
     * Service-Layer ist die Konvention.
     *
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
     *
     * @throws JsonException
     */
    private function hydrate(array $row): Unit
    {
        $tags = [];
        if (isset($row['tags']) && '' !== $row['tags']) {
            $decoded = json_decode((string) $row['tags'], true, 16, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                // Reine Strings; alles andere ignorieren, damit Korruption
                // einzelner Tags die ganze Unit nicht killt.
                $tags = array_values(array_filter(
                    $decoded,
                    static fn ($v) => is_string($v),
                ));
            }
        }

        $sourceType = null;
        if (isset($row['source_type']) && '' !== $row['source_type']) {
            // Bei unbekanntem DB-Wert: fail loud (lieber jetzt als bei
            // stiller Falschverarbeitung weiter unten).
            $sourceType = SourceType::from((string) $row['source_type']);
        }

        return new Unit(
            id: (int) $row['id'],
            namespace: (string) $row['namespace'],
            unitKey: (string) $row['unit_key'],
            context: isset($row['context']) ? (string) $row['context'] : '',
            sourceType: $sourceType,
            sourceRef: isset($row['source_ref']) ? (string) $row['source_ref'] : null,
            sourceHash: isset($row['source_hash']) ? (string) $row['source_hash'] : null,
            tags: $tags,
            notes: isset($row['notes']) ? (string) $row['notes'] : null,
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
