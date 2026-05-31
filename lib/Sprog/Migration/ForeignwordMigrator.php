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
 * Migriert v1-Foreignwords in das v2-Unit/Translation-Schema.
 *
 * Schema-Mapping:
 *   v1 rex_sprog_foreignword (id, clang_id, foreignword, lang, text?, status)
 *       - eine Row pro (clang_id, foreignword), UNIQUE indexed
 *       - lang (varchar(2)) = Quell-Sprache des Fremdwortes für das
 *         <span lang="xx">-Attribut im Frontend
 *
 *   → v2:
 *       - sprog_unit:        eine Row pro DISTINCT foreignword-String
 *         namespace='foreignword', unit_key=$foreignword,
 *         source_type=foreignword, tags=['lang:xx'] (sofern in v1 gesetzt)
 *
 *       - sprog_translation: eine Row pro v1-Row
 *
 * Designentscheidung zu lang: in v1 ist lang per Row (Translation-Ebene)
 * gepflegt — konzeptuell aber ist das Wort selbst aus einer Quell-Sprache,
 * unabhängig in welcher clang es erscheint. Wir mappen lang daher auf
 * Unit-Ebene als Tag `lang:xx`. Bei inkonsistenten Bestandsdaten
 * (verschiedene lang-Werte pro Gruppe) gewinnt der erste nicht-leere Wert;
 * der Admin kann das post-migration korrigieren.
 *
 * Das v1-status-Feld ignorieren wir wie beim AbbreviationMigrator.
 */
final class ForeignwordMigrator implements MigratorInterface
{
    private const V1_TABLE = 'sprog_foreignword';

    public function __construct(
        private readonly UnitRepository $units = new UnitRepository(),
        private readonly TranslationRepository $translations = new TranslationRepository(),
    ) {}

    public function name(): string
    {
        return 'foreignword';
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
        $rows = $sql->getArray('SELECT COUNT(DISTINCT foreignword) AS cnt FROM ' . $this->v1Table());

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

        $sql = rex_sql::factory();
        $groupRows = $sql->getArray(
            'SELECT foreignword, MIN(id) AS min_id
             FROM ' . $v1Table . '
             WHERE id > :last_id
             GROUP BY foreignword
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
            $foreignWord = trim((string) ($groupRow['foreignword'] ?? ''));
            $minId = (int) $groupRow['min_id'];

            if (null === $newLastId || $minId > $newLastId) {
                $newLastId = $minId;
            }

            if ('' === $foreignWord) {
                continue;
            }

            if (null !== $this->units->findByKey('foreignword', $foreignWord)) {
                ++$processed;
                continue;
            }

            $rows = rex_sql::factory()->getArray(
                'SELECT clang_id, lang FROM ' . $v1Table . '
                 WHERE foreignword = :word
                 ORDER BY clang_id',
                ['word' => $foreignWord],
            );

            if ([] === $rows) {
                continue;
            }

            // Quell-Sprache als Unit-Tag — siehe Klassen-Doc zur Designwahl.
            // Strikte Validierung: nur zwei Kleinbuchstaben (ISO 639-1-Form),
            // sonst tag verwerfen — verhindert, dass kaputte v1-Daten beliebige
            // Strings ins Tag-Array schwemmen.
            $unitTags = [];
            foreach ($rows as $row) {
                $lang = strtolower(trim((string) ($row['lang'] ?? '')));
                if (1 === preg_match('/^[a-z]{2}$/', $lang)) {
                    $unitTags[] = 'lang:' . $lang;
                    break;
                }
            }

            $tx = rex_sql::factory();
            $tx->beginTransaction();

            try {
                $unit = $this->units->save(new Unit(
                    id: null,
                    namespace: 'foreignword',
                    unitKey: $foreignWord,
                    sourceType: SourceType::Foreignword,
                    sourceRef: null,
                    sourceHash: null,
                    tags: $unitTags,
                    notes: null,
                ));

                foreach ($rows as $row) {
                    // Foreignword hat in v1 keine eigene "Übersetzung" pro clang
                    // (im Sinne eines abweichenden Textes) — der Eintrag selbst
                    // ist die Markierung. Wir legen pro clang eine Translation
                    // mit value=foreignword an, damit das v2-Modell konsistent
                    // bleibt (jede Unit hat ihre Translations pro clang).
                    $value = $foreignWord;
                    $status = Status::Translated;

                    $this->translations->save(new Translation(
                        id: null,
                        unitId: (int) $unit->id,
                        clangId: (int) $row['clang_id'],
                        value: $value,
                        valueHash: ContentHash::of($value),
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
