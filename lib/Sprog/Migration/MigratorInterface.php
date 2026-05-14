<?php

declare(strict_types=1);

namespace Sprog\Migration;

/**
 * Vertrag für eine konkrete Migration einer v1-Inhaltsart nach v2.
 *
 * Migratoren sind resumable: der MigrationService persistiert pro Lauf
 * den lastProcessedId, ein Folge-Aufruf nimmt von dort weiter. Daher
 * arbeitet migrateChunk() id-basiert (WHERE id > :last) statt offset-basiert,
 * was auch robust gegen parallele DELETEs in der v1-Tabelle bleibt.
 *
 * Idempotenz ist Pflicht: ein Chunk darf mehrfach gegen dieselben Quell-IDs
 * laufen, ohne Duplikate in v2 zu erzeugen. Konkrete Migratoren prüfen
 * deshalb vor dem Insert, ob die Ziel-Unit bereits existiert.
 */
interface MigratorInterface
{
    /**
     * Eindeutiger Quell-Name; identisch zum Key im MigrationState
     * (z.B. 'wildcard', 'abbreviation', 'foreignword').
     */
    public function name(): string;

    /**
     * Ist die v1-Quell-Tabelle in dieser Installation überhaupt vorhanden?
     * Bestandsinstallationen können z.B. Foreignword nicht haben.
     */
    public function isAvailable(): bool;

    /**
     * Anzahl der zu migrierenden logischen Einheiten (nicht zwingend identisch
     * mit Tabellen-Rows — Wildcards z.B. zählen DISTINCT id über alle Sprachen).
     */
    public function totalCount(): int;

    /**
     * Verarbeitet bis zu $chunkSize Einheiten, beginnt nach $lastProcessedId.
     */
    public function migrateChunk(?int $lastProcessedId, int $chunkSize): ChunkResult;
}
