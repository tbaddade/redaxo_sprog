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
 * Migriert v1-Abbreviations in das v2-Unit/Translation-Schema.
 *
 * Schema-Mapping:
 *   v1 rex_sprog_abbreviation (id, clang_id, abbreviation, text, status)
 *       - eine eigene Row pro (clang_id, abbreviation), UNIQUE indexed
 *       - kein gemeinsamer Gruppen-Schlüssel über Sprachen — der Identifier
 *         ist der String "abbreviation" selbst
 *
 *   → v2:
 *       - sprog_unit:        eine Row pro DISTINCT abbreviation-String
 *         namespace='abbreviation', unit_key=$abbreviation,
 *         source_type=abbreviation, source_ref=NULL, source_hash=NULL
 *
 *       - sprog_translation: eine Row pro v1-Row
 *         value=$text, valueHash=sha256($value) bei nicht-leerem value, revision=0
 *
 * Das v1-status-Feld (tinyint: 0/1) wird übernommen: aktiv (1) → approved
 * (unter der approved-only-Frontend-Regel sichtbar), inaktiv (0) → draft
 * (unsichtbar). Leerer Text → missing. So bleibt die v1-Aktiv/Inaktiv-Semantik
 * über die Migration hinweg erhalten.
 *
 * Cursor läuft id-basiert (WHERE id > :last_id) über die MIN(id) pro
 * abbreviation-Gruppe — robust und resumable.
 */
final class AbbreviationMigrator implements MigratorInterface
{
    private const V1_TABLE = 'sprog_abbreviation';

    /** Instanz-Lebenszeit-Cache, damit eine SHOW-TABLES-Query pro Request reicht. */
    private ?bool $availableCache = null;

    public function __construct(
        private readonly UnitRepository $units = new UnitRepository(),
        private readonly TranslationRepository $translations = new TranslationRepository(),
    ) {}

    public function name(): string
    {
        return 'abbreviation';
    }

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
        $rows = $sql->getArray('SELECT COUNT(DISTINCT abbreviation) AS cnt FROM ' . $this->v1Table());

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

        // Gruppen-Cursor: pro abbreviation-String den ältesten id-Wert,
        // sortiert nach diesem ältesten id. Bei Resume nimmt das nur
        // Gruppen mit, deren ältester Eintrag jenseits des Cursors liegt.
        //
        // TODO(v3): Resume-Phase trifft Spät-Rows bereits verarbeiteter
        // Gruppen erneut (id-Liste pro Gruppe nicht zusammenhängend).
        // Idempotenz-Check fängt es ab, aber GROUP BY läuft jedes Mal mit.
        // Alternativen: (a) MAX(id)-Cursor statt MIN(id); (b) Cursor auf
        // abbreviation-String selbst (ORDER BY abbreviation).
        $sql = rex_sql::factory();
        $groupRows = $sql->getArray(
            'SELECT abbreviation, MIN(id) AS min_id
             FROM ' . $v1Table . '
             WHERE id > :last_id
             GROUP BY abbreviation
             ORDER BY MIN(id) ASC
             LIMIT ' . (int) $chunkSize,
            ['last_id' => $lastId],
        );

        if ([] === $groupRows) {
            return new ChunkResult(processed: 0, lastProcessedId: $lastProcessedId, done: true);
        }

        $processed = 0;
        $newLastId = $lastProcessedId;

        foreach ($groupRows as $groupRow) {
            $abbrName = trim((string) ($groupRow['abbreviation'] ?? ''));
            $minId = (int) $groupRow['min_id'];

            // Cursor IMMER fortschreiben — auch wenn die Gruppe geskippt wird;
            // sonst läuft der nächste Aufruf wieder über dieselbe leere/duplikate Gruppe.
            if (null === $newLastId || $minId > $newLastId) {
                $newLastId = $minId;
            }

            if ('' === $abbrName) {
                continue;
            }

            if (null !== $this->units->findByKey('abbreviation', $abbrName)) {
                ++$processed;
                continue;
            }

            $rows = rex_sql::factory()->getArray(
                'SELECT clang_id, text, status FROM ' . $v1Table . '
                 WHERE abbreviation = :abbr
                 ORDER BY clang_id',
                ['abbr' => $abbrName],
            );

            if ([] === $rows) {
                continue;
            }

            $tx = rex_sql::factory();
            $tx->beginTransaction();

            try {
                $unit = $this->units->save(new Unit(
                    id: null,
                    namespace: 'abbreviation',
                    unitKey: $abbrName,
                    sourceType: SourceType::Abbreviation,
                    sourceRef: null,
                    sourceHash: null,
                    tags: [],
                    notes: null,
                ));

                foreach ($rows as $row) {
                    $value = (string) ($row['text'] ?? '');
                    // v1-Status übernehmen: aktiv (1) war live → approved,
                    // inaktiv (0) → draft (unter der approved-only-Regel nicht
                    // sichtbar). Leerer Text → missing.
                    if ('' === $value) {
                        $status = Status::Missing;
                    } else {
                        $status = 1 === (int) ($row['status'] ?? 0) ? Status::Approved : Status::Draft;
                    }

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

                // Fehlende clangs mit missing-Rows auffüllen (siehe WildcardMigrator).
                // TODO(v3 perf): siehe WildcardMigrator — Service vor der Loop
                // instanziieren + Bulk-Pfad für ensureRowsForUnit.
                TranslationService::create()->ensureRowsForUnit($unit);

                $tx->commit();
            } catch (Throwable $e) {
                $tx->rollBack();
                throw $e;
            }

            ++$processed;
        }

        $done = count($groupRows) < $chunkSize;

        return new ChunkResult(processed: $processed, lastProcessedId: $newLastId, done: $done);
    }

    private function v1Table(): string
    {
        return rex::getTable(self::V1_TABLE);
    }
}
