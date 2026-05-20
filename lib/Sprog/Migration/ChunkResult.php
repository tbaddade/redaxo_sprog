<?php

declare(strict_types=1);

namespace Sprog\Migration;

/**
 * Rückgabe-Wert eines einzelnen Migrator-Chunks.
 *
 *  processed       - Anzahl tatsächlich migrierter Einheiten in diesem Chunk
 *                    (kann kleiner als chunkSize sein, z.B. durch Skips bei Idempotenz)
 *  lastProcessedId - höchste verarbeitete Quell-ID; wird vom Service persistiert
 *                    und beim nächsten Chunk als untere Schranke verwendet
 *  done            - true, wenn keine weiteren Quell-Einheiten existieren
 *                    (Migrator hat das Ende erreicht)
 */
final readonly class ChunkResult
{
    public function __construct(
        public int $processed,
        public ?int $lastProcessedId,
        public bool $done,
    ) {}
}
