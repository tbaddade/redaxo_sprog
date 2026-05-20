<?php

declare(strict_types=1);

namespace Sprog\Migration;

use JsonException;

use function is_array;
use function is_string;

/**
 * Aggregat-State über alle Migration-Quellen. Immutable.
 *
 * Persistiert wird der State per JSON-Snapshot in rex_config('sprog', 'migration_state').
 * MigrationService liest/schreibt den State; alle Updates erzeugen eine
 * neue Instanz (withProgress()).
 */
final readonly class MigrationState
{
    /**
     * @param array<string, MigrationProgress> $progressBySource
     */
    public function __construct(
        public array $progressBySource,
    ) {}

    public static function empty(): self
    {
        return new self([]);
    }

    public function for(string $source): ?MigrationProgress
    {
        return $this->progressBySource[$source] ?? null;
    }

    public function withProgress(MigrationProgress $progress): self
    {
        $next = $this->progressBySource;
        $next[$progress->source] = $progress;

        return new self($next);
    }

    /**
     * Gibt es noch unfertige (oder gar nicht gestartete) Quellen?
     */
    public function isComplete(): bool
    {
        if ([] === $this->progressBySource) {
            return false;
        }

        foreach ($this->progressBySource as $progress) {
            if (!$progress->isCompleted()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Erster Migrator, der noch Arbeit hat — praktisch für
     * "weiterarbeiten am ersten unfertigen".
     */
    public function nextPending(): ?MigrationProgress
    {
        foreach ($this->progressBySource as $progress) {
            if (!$progress->isCompleted()) {
                return $progress;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => 1,
            'sources' => array_map(
                static fn (MigrationProgress $p) => $p->toArray(),
                $this->progressBySource,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws JsonException
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['sources']) || !is_array($data['sources'])) {
            return self::empty();
        }

        $progress = [];
        foreach ($data['sources'] as $key => $sourceData) {
            if (!is_array($sourceData) || !is_string($key)) {
                continue;
            }
            $progress[$key] = MigrationProgress::fromArray($sourceData);
        }

        return new self($progress);
    }
}
