<?php

declare(strict_types=1);

namespace Sprog\Repository;

use DateTimeImmutable;
use InvalidArgumentException;
use rex;
use rex_sql;
use rex_sql_exception;
use RuntimeException;
use Sprog\Enum\Status;
use Sprog\Exception\OptimisticLockException;
use Sprog\Model\Translation;

/**
 * Persistenz-Schicht für sprog_translation.
 *
 * Genau eine Row pro (unit_id, clang_id) — durchgesetzt über UNIQUE-Index
 * im Schema. Concurrent-Writes auf dasselbe Paar laufen als
 * rex_sql_exception nach oben durch; Locking-Strategie ist Aufgabe
 * des TranslationService.
 */
final class TranslationRepository
{
    private const TABLE = 'sprog_translation';

    /**
     * @throws rex_sql_exception
     */
    public function find(int $id): ?Translation
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
     */
    public function findForUnitAndClang(int $unitId, int $clangId): ?Translation
    {
        $sql = rex_sql::factory();
        $rows = $sql->getArray(
            'SELECT * FROM ' . $this->tableName() . '
             WHERE unit_id = :unit_id AND clang_id = :clang_id
             LIMIT 1',
            ['unit_id' => $unitId, 'clang_id' => $clangId],
        );

        if ([] === $rows) {
            return null;
        }

        return $this->hydrate($rows[0]);
    }

    /**
     * Alle Übersetzungen einer Unit, über alle Sprachen.
     *
     * @throws rex_sql_exception
     * @return list<Translation>
     */
    public function findByUnit(int $unitId): array
    {
        $sql = rex_sql::factory();
        $rows = $sql->getArray(
            'SELECT * FROM ' . $this->tableName() . '
             WHERE unit_id = :unit_id
             ORDER BY clang_id',
            ['unit_id' => $unitId],
        );

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * Bulk-Loader: alle Übersetzungen zu mehreren Units in einem Query.
     * Spart das N+1, das Inbox-Akkordeon sonst auslösen würde.
     *
     * @param list<int> $unitIds
     *
     * @throws rex_sql_exception
     * @return array<int, array<int, Translation>>  unitId → clangId → Translation
     */
    public function findByUnits(array $unitIds): array
    {
        if ([] === $unitIds) {
            return [];
        }

        // IDs hier explizit ge-int-cast, damit der IN-Build Injection-frei
        // ist (named-params in IN-Listen sind treiberabhängig nervig).
        $ints = array_map(static fn ($id) => (int) $id, $unitIds);
        $inClause = implode(',', $ints);

        $sql = rex_sql::factory();
        $rows = $sql->getArray(
            'SELECT * FROM ' . $this->tableName() . '
             WHERE unit_id IN (' . $inClause . ')
             ORDER BY unit_id, clang_id',
        );

        $byUnit = [];
        foreach ($rows as $row) {
            $t = $this->hydrate($row);
            $byUnit[$t->unitId][$t->clangId] = $t;
        }

        return $byUnit;
    }

    /**
     * Inbox-Query: alle Übersetzungen in einer Sprache mit gegebenem Status.
     * limit + offset ohne Default, weil der Caller über die Paginierung
     * bewusst entscheiden muss (Inbox-Lazy-Loading vs. Full-Export).
     *
     * @throws rex_sql_exception
     * @return list<Translation>
     */
    public function findByClangAndStatus(int $clangId, Status $status, int $limit, int $offset): array
    {
        if ($limit <= 0 || $offset < 0) {
            throw new InvalidArgumentException('limit muss > 0 und offset >= 0 sein.');
        }

        // LIMIT/OFFSET nicht als named params: einige PDO-Treiber binden
        // sie als String, MySQL erwartet aber Integer. Beide Werte sind
        // oben validiert und werden hier explizit ge-int-cast — damit
        // ist die Konkatenation Injection-frei.
        $sql = rex_sql::factory();
        $rows = $sql->getArray(
            'SELECT * FROM ' . $this->tableName() . '
             WHERE clang_id = :clang_id AND status = :status
             ORDER BY updatedate DESC, id DESC
             LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset,
            [
                'clang_id' => $clangId,
                'status' => $status->value,
            ],
        );

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * Anlegen oder Aktualisieren ohne Optimistic-Lock-Check.
     *
     * Bei Update wird die revision automatisch um 1 erhöht. Geeignet für
     * Migration / System-Aktionen, die nicht mit konkurrentem Backend-Edit
     * zusammenstoßen. Für Backend-Edits siehe saveWithLock().
     *
     * @throws rex_sql_exception
     */
    public function save(Translation $translation): Translation
    {
        if (null === $translation->id) {
            return $this->doInsert($translation);
        }

        $sql = rex_sql::factory();
        $sql->setTable($this->tableName());
        $this->applyColumns($sql, $translation);
        $sql->setValue('revision', $translation->revision + 1);
        $sql->addGlobalUpdateFields();
        $sql->setWhere('id = :id', ['id' => $translation->id]);
        $sql->update();

        return $this->find($translation->id) ?? throw new RuntimeException('Translation ' . $translation->id . ' nach Update nicht auffindbar.');
    }

    /**
     * Aktualisieren mit Optimistic-Lock-Check.
     *
     * Das UPDATE wird zusätzlich auf revision = $translation->revision
     * eingeschränkt. Wenn 0 Zeilen betroffen sind, hat ein anderer Prozess
     * die Translation zwischenzeitlich verändert (oder gelöscht) — wir werfen
     * OptimisticLockException, statt die fremden Änderungen zu überschreiben.
     *
     * Bei einem Insert (id = null) gibt es keinen Lock-Check, die UNIQUE-
     * Constraint auf (unit_id, clang_id) schützt vor konkurrenten Creates.
     *
     * @throws rex_sql_exception
     * @throws OptimisticLockException
     */
    public function saveWithLock(Translation $translation): Translation
    {
        if (null === $translation->id) {
            return $this->doInsert($translation);
        }

        $sql = rex_sql::factory();
        $sql->setTable($this->tableName());
        $this->applyColumns($sql, $translation);
        $sql->setValue('revision', $translation->revision + 1);
        $sql->addGlobalUpdateFields();
        $sql->setWhere(
            'id = :id AND revision = :rev',
            ['id' => $translation->id, 'rev' => $translation->revision],
        );
        $sql->update();

        // rex_sql::getRows() nach UPDATE = affected rows (PDO::rowCount()).
        // 0 = entweder Row weg oder Revision-Mismatch — beides als
        // Konflikt-Signal an den Service zurückgeben.
        if (0 === $sql->getRows()) {
            throw new OptimisticLockException($translation->id, $translation->revision);
        }

        return $this->find($translation->id) ?? throw new RuntimeException('Translation ' . $translation->id . ' nach Update nicht auffindbar.');
    }

    /**
     * @throws rex_sql_exception
     */
    private function doInsert(Translation $translation): Translation
    {
        $sql = rex_sql::factory();
        $sql->setTable($this->tableName());
        $this->applyColumns($sql, $translation);
        $sql->setValue('revision', 0);
        $sql->addGlobalCreateFields();
        $sql->addGlobalUpdateFields();
        $sql->insert();
        $newId = (int) $sql->getLastId();

        return $this->find($newId) ?? throw new RuntimeException('Translation ' . $newId . ' nach Insert nicht auffindbar.');
    }

    /**
     * Setzt die Wert-Spalten — ohne revision, ohne global fields.
     * Wird sowohl vom Insert- als auch vom Update-Pfad genutzt, damit die
     * Spalten-Liste an genau einer Stelle gepflegt wird.
     */
    private function applyColumns(rex_sql $sql, Translation $translation): void
    {
        $sql->setValue('unit_id', $translation->unitId);
        $sql->setValue('clang_id', $translation->clangId);
        $sql->setValue('value', $translation->value);
        $sql->setValue('value_hash', $translation->valueHash);
        $sql->setValue('source_hash_at_translation', $translation->sourceHashAtTranslation);
        $sql->setValue('status', $translation->status->value);
        $sql->setValue('mt_provider', $translation->mtProvider);
        $sql->setValue('mt_confidence', $translation->mtConfidence);
        $sql->setValue('translator_id', $translation->translatorId);
        $sql->setValue('reviewer_id', $translation->reviewerId);
    }

    /**
     * Alle Übersetzungen einer Sprache als stale markieren — z.B. nach
     * einem Bulk-Import, der die Quell-Sprache komplett neu setzt.
     *
     * Atomarer Single-UPDATE statt Schleife, sonst killt das die Performance
     * bei mehreren tausend Translations.
     *
     * @throws rex_sql_exception
     * @return int Anzahl der betroffenen Rows
     */
    public function markStaleForUnit(int $unitId): int
    {
        $sql = rex_sql::factory();
        $sql->setQuery(
            'UPDATE ' . $this->tableName() . '
             SET status = :stale, revision = revision + 1, updatedate = NOW()
             WHERE unit_id = :unit_id AND status IN (:translated, :needs_review, :approved)',
            [
                'stale' => Status::Stale->value,
                'unit_id' => $unitId,
                'translated' => Status::Translated->value,
                'needs_review' => Status::NeedsReview->value,
                'approved' => Status::Approved->value,
            ],
        );

        return $sql->getRows();
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

    /**
     * Alle Translations einer Unit löschen — wird vom UnitRepository-Caller
     * bei Unit-Löschung benötigt, da wir auf DB-Ebene keine FK-Cascades
     * setzen (REDAXO-Konvention).
     *
     * @throws rex_sql_exception
     */
    public function deleteByUnit(int $unitId): void
    {
        $sql = rex_sql::factory();
        $sql->setQuery(
            'DELETE FROM ' . $this->tableName() . ' WHERE unit_id = :unit_id',
            ['unit_id' => $unitId],
        );
    }

    private function tableName(): string
    {
        return rex::getTable(self::TABLE);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Translation
    {
        // Status::from wirft \ValueError bei unbekanntem DB-Wert; das ist
        // gewollt — lieber jetzt fehlschlagen als stillschweigend falsch
        // weiterverarbeiten.
        $status = Status::from((string) $row['status']);

        return new Translation(
            id: (int) $row['id'],
            unitId: (int) $row['unit_id'],
            clangId: (int) $row['clang_id'],
            value: (string) $row['value'],
            valueHash: isset($row['value_hash']) ? (string) $row['value_hash'] : null,
            sourceHashAtTranslation: isset($row['source_hash_at_translation']) ? (string) $row['source_hash_at_translation'] : null,
            status: $status,
            mtProvider: isset($row['mt_provider']) ? (string) $row['mt_provider'] : null,
            mtConfidence: isset($row['mt_confidence']) ? (float) $row['mt_confidence'] : null,
            translatorId: isset($row['translator_id']) ? (int) $row['translator_id'] : null,
            reviewerId: isset($row['reviewer_id']) ? (int) $row['reviewer_id'] : null,
            revision: (int) $row['revision'],
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
