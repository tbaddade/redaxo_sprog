<?php

declare(strict_types=1);

namespace Sprog\Migration;

use DateTimeImmutable;
use JsonException;

use function is_string;

/**
 * Fortschritt einer einzelnen Migration-Quelle.
 *
 * Persistiert wird über rex_config als JSON. Die toArray/fromArray-Methoden
 * fixieren das Wire-Format; bei zukünftigen Schema-Änderungen hier zentral
 * Migrations-Logik einziehen (z.B. Version-Feld einlesen).
 */
final readonly class MigrationProgress
{
    public function __construct(
        public string $source,
        public int $totalRows,
        public int $processedRows,
        public ?int $lastProcessedId,
        public ?string $lastError,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $completedAt,
    ) {}

    public static function pending(string $source, int $totalRows): self
    {
        return new self(
            source: $source,
            totalRows: $totalRows,
            processedRows: 0,
            lastProcessedId: null,
            lastError: null,
            startedAt: null,
            completedAt: null,
        );
    }

    public function isCompleted(): bool
    {
        return null !== $this->completedAt;
    }

    public function isStarted(): bool
    {
        return null !== $this->startedAt;
    }

    /**
     * Migrations-Fortschritt in Prozent (0–100).
     *
     * Bei totalRows = 0 (nichts zu migrieren) liefert die Funktion ebenfalls
     * 100 — semantisch korrekt im Sinne von „done", aber UI-seitig
     * irreführend, wenn ein 100%-Balken angezeigt wird, obwohl gar keine
     * Quelle vorhanden ist. UI-Code bitte vorab `isEmpty()` prüfen und einen
     * eigenen „nichts zu tun"-Zustand rendern.
     */
    public function percent(): int
    {
        if ($this->totalRows <= 0) {
            return 100;
        }

        return (int) min(100, floor($this->processedRows * 100 / $this->totalRows));
    }

    /**
     * True, wenn die Quelle gar keine Rows hat (Bestandsinstallation ohne
     * sprog-Daten o.ä.). Distinct vom „fertig durchmigriert"-Zustand, der
     * mit totalRows > 0 und processedRows >= totalRows einhergeht.
     */
    public function isEmpty(): bool
    {
        return $this->totalRows <= 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'total_rows' => $this->totalRows,
            'processed_rows' => $this->processedRows,
            'last_processed_id' => $this->lastProcessedId,
            'last_error' => $this->lastError,
            'started_at' => $this->startedAt?->format('Y-m-d H:i:s'),
            'completed_at' => $this->completedAt?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws JsonException
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['source']) || !is_string($data['source'])) {
            throw new JsonException('MigrationProgress.source fehlt oder hat falschen Typ.');
        }

        return new self(
            source: $data['source'],
            totalRows: isset($data['total_rows']) ? (int) $data['total_rows'] : 0,
            processedRows: isset($data['processed_rows']) ? (int) $data['processed_rows'] : 0,
            lastProcessedId: isset($data['last_processed_id']) ? (int) $data['last_processed_id'] : null,
            lastError: isset($data['last_error']) && is_string($data['last_error']) ? $data['last_error'] : null,
            startedAt: isset($data['started_at']) && is_string($data['started_at']) ? new DateTimeImmutable($data['started_at']) : null,
            completedAt: isset($data['completed_at']) && is_string($data['completed_at']) ? new DateTimeImmutable($data['completed_at']) : null,
        );
    }
}
