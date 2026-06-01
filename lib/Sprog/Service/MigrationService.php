<?php

declare(strict_types=1);

namespace Sprog\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use rex_config;
use RuntimeException;
use Sprog\Migration\AbbreviationMigrator;
use Sprog\Migration\ForeignwordMigrator;
use Sprog\Migration\MigrationProgress;
use Sprog\Migration\MigrationState;
use Sprog\Migration\MigratorInterface;
use Sprog\Migration\WildcardMigrator;
use Throwable;

use function is_array;
use function is_string;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Orchestriert die v1 → v2 Datenmigration.
 *
 * Ablauf (vom Admin im Backend gesteuert):
 *   1. reset() — registriert alle verfügbaren Migratoren, ermittelt
 *      die Total-Counts und schreibt einen frischen State.
 *   2. runChunk($source, $chunkSize) — verarbeitet bis zu $chunkSize
 *      Einheiten der gewählten Quelle. Wird vom Backend wiederholt
 *      aufgerufen (Pattern wie das bestehende Copy-Feature), bis der
 *      State für diese Quelle completed ist.
 *   3. Wiederholen für jede Quelle, bis state.isComplete() true ergibt.
 *
 * Konkurrente Chunks (z.B. zwei offene Browser-Tabs) werden durch die
 * Idempotenz der Migratoren abgefangen: pro Quell-Einheit prüft der
 * Migrator vorher, ob die v2-Unit bereits existiert. Schlimmstenfalls
 * verarbeitet derselbe Chunk doppelt — ohne Datenkorruption.
 */
final class MigrationService
{
    private const CONFIG_NAMESPACE = 'sprog';
    private const CONFIG_KEY = 'migration_state';

    private const MIN_CHUNK_SIZE = 1;
    private const MAX_CHUNK_SIZE = 1000;

    /**
     * @param array<string, MigratorInterface> $migrators
     */
    public function __construct(
        private readonly array $migrators,
        private readonly ActivityService $activity,
    ) {
        foreach ($migrators as $key => $migrator) {
            if (!is_string($key) || $key !== $migrator->name()) {
                throw new InvalidArgumentException('Migrator-Key muss identisch zum Migrator::name() sein.');
            }
        }
    }

    public static function create(): self
    {
        $migrators = [];
        foreach (
            [
                new WildcardMigrator(),
                new AbbreviationMigrator(),
                new ForeignwordMigrator(),
            ] as $migrator
        ) {
            $migrators[$migrator->name()] = $migrator;
        }

        return new self($migrators, ActivityService::create());
    }

    /**
     * @return array<string, MigratorInterface>
     */
    public function migrators(): array
    {
        return $this->migrators;
    }

    /**
     * @throws JsonException
     */
    public function state(): MigrationState
    {
        $stored = rex_config::get(self::CONFIG_NAMESPACE, self::CONFIG_KEY);
        if (null === $stored || '' === $stored) {
            return MigrationState::empty();
        }

        if (!is_string($stored)) {
            // rex_config kann technisch auch Arrays liefern, je nach Set-Pfad —
            // wir akzeptieren beides, weil wir oben JSON-string speichern.
            if (is_array($stored)) {
                return MigrationState::fromArray($stored);
            }

            return MigrationState::empty();
        }

        $decoded = json_decode($stored, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            return MigrationState::empty();
        }

        return MigrationState::fromArray($decoded);
    }

    /**
     * Initialisiert den State neu: alle verfügbaren Migratoren mit ihrem
     * totalCount, processedRows = 0, kein Fehler, kein Start-Zeitstempel.
     *
     * Achtung: das setzt NUR den Fortschritts-Snapshot zurück. Die
     * bereits in v2 angelegten Units und Translations bleiben — die
     * Migratoren überspringen sie wegen Idempotenz beim nächsten Lauf.
     *
     * @throws JsonException
     */
    public function reset(?int $userId = null): MigrationState
    {
        $progressBySource = [];

        foreach ($this->migrators as $key => $migrator) {
            if (!$migrator->isAvailable()) {
                continue;
            }
            $progressBySource[$key] = MigrationProgress::pending($key, $migrator->totalCount());
        }

        $state = new MigrationState($progressBySource);
        $this->persist($state);

        $this->activity->log('migration.reset', null, null, $userId, [
            'sources' => array_keys($progressBySource),
        ]);

        return $state;
    }

    /**
     * Verarbeitet einen Chunk einer Quelle. Aktualisiert den State und
     * gibt den neuen Stand für diese Quelle zurück.
     *
     * @throws JsonException
     */
    public function runChunk(string $source, int $chunkSize, ?int $userId = null): MigrationProgress
    {
        if ($chunkSize < self::MIN_CHUNK_SIZE || $chunkSize > self::MAX_CHUNK_SIZE) {
            throw new InvalidArgumentException(sprintf('chunkSize muss im Bereich [%d, %d] liegen, ist %d.', self::MIN_CHUNK_SIZE, self::MAX_CHUNK_SIZE, $chunkSize));
        }

        if (!isset($this->migrators[$source])) {
            throw new InvalidArgumentException('Unbekannter Migration-Source: ' . $source);
        }

        $migrator = $this->migrators[$source];
        if (!$migrator->isAvailable()) {
            throw new RuntimeException('Migrator "' . $source . '" ist in dieser Installation nicht verfügbar.');
        }

        $state = $this->state();
        $progress = $state->for($source);

        if (null === $progress) {
            // Erstmaliger Aufruf für diese Quelle ohne reset() davor — wir
            // füllen den fehlenden Eintrag silent. Das schützt vor
            // "vergessenem reset()", korrekter Total-Count wird sowieso ermittelt.
            $progress = MigrationProgress::pending($source, $migrator->totalCount());
        }

        if ($progress->isCompleted()) {
            return $progress;
        }

        // Beim allerersten Chunk: started_at setzen.
        $startedAt = $progress->startedAt ?? new DateTimeImmutable();

        try {
            $result = $migrator->migrateChunk($progress->lastProcessedId, $chunkSize);
        } catch (Throwable $e) {
            $errorProgress = new MigrationProgress(
                source: $progress->source,
                totalRows: $progress->totalRows,
                processedRows: $progress->processedRows,
                lastProcessedId: $progress->lastProcessedId,
                lastError: substr($e->getMessage(), 0, 1000),
                startedAt: $startedAt,
                completedAt: null,
            );
            $this->persist($state->withProgress($errorProgress));

            $this->activity->log('migration.error', null, null, $userId, [
                'source' => $source,
                'error' => substr($e->getMessage(), 0, 200),
            ]);

            throw $e;
        }

        $nextProcessed = $progress->processedRows + $result->processed;
        $completedAt = $result->done ? new DateTimeImmutable() : null;

        $nextProgress = new MigrationProgress(
            source: $progress->source,
            totalRows: $progress->totalRows,
            processedRows: $nextProcessed,
            lastProcessedId: $result->lastProcessedId,
            lastError: null,
            startedAt: $startedAt,
            completedAt: $completedAt,
        );

        $this->persist($state->withProgress($nextProgress));

        if ($result->done) {
            $this->activity->log('migration.completed', null, null, $userId, [
                'source' => $source,
                'processed_rows' => $nextProcessed,
            ]);
        }
        // Bewusst kein migration.chunk-Log: bei 10k Units × Chunk-Size 50
        // wären das 200 Audit-Einträge pro Quelle und würden den Nutz-Audit
        // (Status-Übergänge etc.) verwässern. Chunk-Fortschritt liegt im
        // MigrationProgress-State und reicht für die UI-Anzeige.

        return $nextProgress;
    }

    /**
     * @throws JsonException
     */
    private function persist(MigrationState $state): void
    {
        rex_config::set(
            self::CONFIG_NAMESPACE,
            self::CONFIG_KEY,
            json_encode($state->toArray(), JSON_THROW_ON_ERROR),
        );
    }
}
