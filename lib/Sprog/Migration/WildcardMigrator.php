<?php

declare(strict_types=1);

namespace Sprog\Migration;

use InvalidArgumentException;
use rex;
use rex_sql;
use rex_sql_exception;
use Sprog\Enum\SourceType;
use Sprog\Enum\Status;
use Sprog\Model\Translation;
use Sprog\Model\Unit;
use Sprog\Repository\TranslationRepository;
use Sprog\Repository\UnitRepository;
use Sprog\Service\TranslationService;
use Sprog\Support\ContentHash;
use Throwable;

use function count;

use const PHP_INT_MIN;

/**
 * Migriert v1-Wildcards in das v2-Unit/Translation-Schema.
 *
 * Schema-Mapping:
 *   v1 rex_sprog_wildcard (pid, id, clang_id, wildcard, replace)
 *       - id   = Gruppen-Schlüssel über Sprachen
 *       - pid  = PK pro Sprache-Row
 *
 *   → v2:
 *       - sprog_unit:        eine Row pro DISTINCT id
 *         namespace='wildcard', unit_key=$wildcard, source_type=wildcard,
 *         source_ref=NULL, source_hash=NULL (Quell-Sprache ist konzeptuell
 *         nicht definiert — wird beim ersten Edit gesetzt)
 *
 *       - sprog_translation: eine Row pro v1-Row
 *         value=$replace, status=approved (bzw. missing bei leerem replace),
 *         valueHash=sha256($value) bei nicht-leerem value, revision=0
 *
 * Bestandsdaten gehen als 'approved' rein: v1-Wildcards hatten keinen Status
 * und waren live/veröffentlicht. Unter der approved-only-Frontend-Regel bleiben
 * sie damit nach dem Deploy sichtbar; der Review-Workflow greift nur für neue
 * und MT-Übersetzungen.
 *
 * Idempotenz: vor jedem Gruppen-Insert prüfen wir, ob eine Unit mit
 * (namespace='wildcard', unit_key=$wildcardName) bereits existiert.
 * Falls ja, überspringen — bereits migriert.
 *
 * TODO(v3): Konstruktor, isAvailable(), migrateChunk()-Loop-Skelett,
 * Transaktions-Wrapping und ensureRowsForUnit() teilen ~80% Struktur mit
 * AbbreviationMigrator und ForeignwordMigrator. Ein AbstractMigrator mit
 * Template-Methoden loadGroupCursor(), mapToUnit(), mapToTranslation()
 * würde die drei Klassen auf je ~50 LOC fachliche Differenz reduzieren.
 */
final class WildcardMigrator implements MigratorInterface
{
    private const V1_TABLE = 'sprog_wildcard';

    public function __construct(
        private readonly UnitRepository $units = new UnitRepository(),
        private readonly TranslationRepository $translations = new TranslationRepository(),
    ) {}

    public function name(): string
    {
        return 'wildcard';
    }

    /** Instanz-Lebenszeit-Cache, damit eine SHOW-TABLES-Query pro Request reicht. */
    private ?bool $availableCache = null;

    public function isAvailable(): bool
    {
        if (null !== $this->availableCache) {
            return $this->availableCache;
        }
        try {
            $sql = rex_sql::factory();
            $sql->setQuery('SHOW TABLES LIKE :name', ['name' => $this->v1Table()]);
            return $this->availableCache = $sql->getRows() > 0;
        } catch (rex_sql_exception) {
            return $this->availableCache = false;
        }
    }

    public function totalCount(): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }

        $sql = rex_sql::factory();
        $rows = $sql->getArray('SELECT COUNT(DISTINCT id) AS cnt FROM ' . $this->v1Table());

        return (int) ($rows[0]['cnt'] ?? 0);
    }

    public function migrateChunk(?int $lastProcessedId, int $chunkSize): ChunkResult
    {
        if ($chunkSize < 1) {
            throw new InvalidArgumentException('chunkSize muss >= 1 sein.');
        }
        if (!$this->isAvailable()) {
            return new ChunkResult(processed: 0, lastProcessedId: $lastProcessedId, done: true);
        }

        $v1Table = $this->v1Table();
        $lastId = $lastProcessedId ?? PHP_INT_MIN;

        // Nur DISTINCT id verarbeiten (eine Gruppe pro Wildcard).
        // chunkSize wird (int)-cast, weil PDO-LIMIT mit named params
        // treiberabhängig ist (siehe TranslationRepository::findByClangAndStatus).
        $sql = rex_sql::factory();
        $idRows = $sql->getArray(
            'SELECT DISTINCT id FROM ' . $v1Table . '
             WHERE id > :last_id
             ORDER BY id ASC
             LIMIT ' . (int) $chunkSize,
            ['last_id' => $lastId],
        );

        if ([] === $idRows) {
            return new ChunkResult(processed: 0, lastProcessedId: $lastProcessedId, done: true);
        }

        $processed = 0;
        $newLastId = $lastProcessedId;

        foreach ($idRows as $idRow) {
            $groupId = (int) $idRow['id'];
            $newLastId = $groupId;

            $groupRows = rex_sql::factory()->getArray(
                'SELECT wildcard, clang_id, `replace` FROM ' . $v1Table . '
                 WHERE id = :id
                 ORDER BY clang_id',
                ['id' => $groupId],
            );

            if ([] === $groupRows) {
                continue;
            }

            $wildcardName = trim((string) ($groupRows[0]['wildcard'] ?? ''));
            if ('' === $wildcardName) {
                // Fehlerhafte Bestandsdaten (leerer wildcard-Name): überspringen,
                // sonst würde die UNIQUE-Constraint später hart aufschlagen.
                continue;
            }

            // Idempotenz: bereits migriert? Überspringen.
            if (null !== $this->units->findByKey('wildcard', $wildcardName)) {
                ++$processed;
                continue;
            }

            // Gruppe als Einheit anlegen — Unit + alle zugehörigen Translations
            // in einer Transaktion, damit Halb-Migrationen ausgeschlossen sind.
            // Bei Fehler: rollback, Fehler an MigrationService propagieren.
            $tx = rex_sql::factory();
            $tx->beginTransaction();

            try {
                $unit = $this->units->save(new Unit(
                    id: null,
                    namespace: 'wildcard',
                    unitKey: $wildcardName,
                    sourceType: SourceType::Wildcard,
                    sourceRef: null,
                    sourceHash: null,
                    tags: [],
                    notes: null,
                ));

                foreach ($groupRows as $row) {
                    $value = (string) ($row['replace'] ?? '');
                    // v1-Wildcards hatten keinen Status und waren immer live →
                    // approved (sichtbar unter der approved-only-Frontend-Regel).
                    $status = '' === $value ? Status::Missing : Status::Approved;

                    $this->translations->save(new Translation(
                        id: null,
                        unitId: (int) $unit->id,
                        clangId: (int) $row['clang_id'],
                        value: $value,
                        valueHash: '' === $value ? null : ContentHash::of($value),
                        sourceHashAtTranslation: null,
                        status: $status,
                        mtProvider: null,
                        mtConfidence: null,
                        translatorId: null,
                        reviewerId: null,
                        revision: 0,
                    ));
                }

                // Fehlende clangs (in v1 nie befüllt) mit missing-Rows auffüllen,
                // damit jede Unit für alle clangs eine Row hat — Inbox-Filter konsistent.
                // TODO(v3 perf): TranslationService einmal vor der Loop bauen
                // (Constructor-DI) statt pro Iteration; zusätzlich einen Bulk-Pfad
                // TranslationRepository::ensureMissingForUnit(int, list<int>) via
                // INSERT IGNORE … SELECT clang_id FROM rex_clang einziehen.
                // Heute: 10k Wildcards × 5 clangs × 2 = 100k Roundtrips.
                TranslationService::create()->ensureRowsForUnit($unit);

                $tx->commit();
            } catch (Throwable $e) {
                $tx->rollBack();
                throw $e;
            }

            ++$processed;
        }

        // done, wenn dieser Chunk weniger Einheiten lieferte als angefordert
        $done = count($idRows) < $chunkSize;

        return new ChunkResult(processed: $processed, lastProcessedId: $newLastId, done: $done);
    }

    private function v1Table(): string
    {
        return rex::getTable(self::V1_TABLE);
    }
}
