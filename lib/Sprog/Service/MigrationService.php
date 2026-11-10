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
 * Orchestriert die einmalige v1 → v2 Datenmigration.
 *
 * Ablauf: Nach dem Update von 1.x leitet Sprog Admins auf die Migrationsseite
 * (Boot\MigrationGate). Dort stößt ein einziger Button runNextChunk() so lange
 * an, bis alle Quellen (Platzhalter, Abkürzungen, Fremdwörter) übertragen sind.
 * Die Chunks verhindern Timeouts; bricht ein Lauf ab, setzt der nächste Klick
 * an derselben Stelle fort.
 *
 * Danach wird das Flag `migration_done` gesetzt — endgültig: Es gibt keinen
 * Reset und keinen zweiten Lauf (ein erneuter Lauf würde in der Inbox
 * gelöschte Units aus den v1-Tabellen wieder anlegen). Die v1-Tabellen
 * bleiben als Sicherung liegen, werden aber nicht mehr gelesen.
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
    // Gesetzt, sobald nichts (mehr) zu migrieren ist — danach endgültig erledigt.
    private const DONE_KEY = 'migration_done';
    // Alt-Flag der früheren install.php-Auto-Migration (v2.0.0-beta ≤ 5).
    private const LEGACY_DONE_KEY = 'migration_autorun_done';

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

        // persist() schreibt immer einen JSON-String — alles andere ist Fremdwert/Korruption.
        if (!is_string($stored)) {
            return MigrationState::empty();
        }

        $decoded = json_decode($stored, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            return MigrationState::empty();
        }

        return MigrationState::fromArray($decoded);
    }

    /**
     * Ist die Migration endgültig erledigt? Reine Config-Abfrage, ohne DB-Zugriff.
     */
    public function isDone(): bool
    {
        return (bool) rex_config::get(self::CONFIG_NAMESPACE, self::DONE_KEY, false)
            || (bool) rex_config::get(self::CONFIG_NAMESPACE, self::LEGACY_DONE_KEY, false);
    }

    /**
     * Steht die v1 → v2 Migration noch aus? Gibt es nichts (mehr) zu migrieren
     * (Neuinstallation ohne v1-Tabellen, leere v1-Tabellen, alle Quellen
     * durch), wird das Flag gesetzt — ab dann kostet die Prüfung nur noch
     * einen Config-Zugriff.
     *
     * @throws JsonException
     */
    public function isMigrationPending(): bool
    {
        if ($this->isDone()) {
            return false;
        }

        if (null === $this->nextSource()) {
            $this->markDone();

            return false;
        }

        return true;
    }

    /**
     * Verarbeitet einen Chunk der nächsten unfertigen Quelle. Ist danach keine
     * Quelle mehr offen, wird die Migration als erledigt markiert (isDone()).
     *
     * @throws RuntimeException wenn die Migration bereits erledigt ist
     * @throws JsonException
     * @return MigrationProgress|null Stand der bearbeiteten Quelle; null, wenn es nichts zu tun gab
     */
    public function runNextChunk(int $chunkSize, ?int $userId = null): ?MigrationProgress
    {
        if ($this->isDone()) {
            throw new RuntimeException('Die Migration ist bereits abgeschlossen.');
        }

        $source = $this->nextSource();
        $progress = null === $source ? null : $this->runChunk($source, $chunkSize, $userId);

        if (null === $this->nextSource()) {
            $this->markDone();
        }

        return $progress;
    }

    /**
     * Erste verfügbare Quelle, die noch nicht abgeschlossen ist. Quellen ohne
     * bisherigen Fortschritt zählen nur, wenn ihre v1-Tabelle Daten enthält.
     *
     * @throws JsonException
     */
    private function nextSource(): ?string
    {
        $state = $this->state();
        foreach ($this->migrators as $name => $migrator) {
            if (!$migrator->isAvailable()) {
                continue;
            }
            $progress = $state->for($name);
            if (null === $progress) {
                if ($migrator->totalCount() > 0) {
                    return $name;
                }
                continue;
            }
            if (!$progress->isCompleted()) {
                return $name;
            }
        }

        return null;
    }

    private function markDone(): void
    {
        rex_config::set(self::CONFIG_NAMESPACE, self::DONE_KEY, true);
    }

    /**
     * Verarbeitet einen Chunk einer Quelle (aus nextSource(), also verfügbar
     * und unfertig). Aktualisiert den State und gibt den neuen Stand zurück.
     *
     * @throws JsonException
     */
    private function runChunk(string $source, int $chunkSize, ?int $userId = null): MigrationProgress
    {
        if ($chunkSize < self::MIN_CHUNK_SIZE || $chunkSize > self::MAX_CHUNK_SIZE) {
            throw new InvalidArgumentException(sprintf('chunkSize muss im Bereich [%d, %d] liegen, ist %d.', self::MIN_CHUNK_SIZE, self::MAX_CHUNK_SIZE, $chunkSize));
        }

        $migrator = $this->migrators[$source];
        $state = $this->state();
        $progress = $state->for($source);

        if (null === $progress) {
            // Erster Chunk dieser Quelle: Total-Count jetzt ermitteln.
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
