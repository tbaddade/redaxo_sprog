<?php

declare(strict_types=1);

namespace Sprog\Model;

use DateTimeImmutable;
use Sprog\Enum\SourceType;

/**
 * Value Object für eine Übersetzungseinheit (sprog_unit).
 *
 * Sprachunabhängiger Anker: alle clang-spezifischen Werte hängen
 * über sprog_translation an dieser Unit.
 *
 * Immutable. Bei Mutationen produziert der Service-Layer eine neue Instanz.
 */
final readonly class Unit
{
    /**
     * @param ?int                $id          NULL, wenn noch nicht in DB
     * @param string              $namespace   Kategorie / Replacement-Provider, z.B. 'wildcard'
     * @param string              $context     User-definierter Bereich, z.B. 'page.about'; '' wenn leer
     * @param string              $unitKey     Identifier innerhalb des (namespace, context)-Paares
     * @param ?SourceType         $sourceType  Optionale Verknüpfung zu REDAXO-Entität
     * @param ?string             $sourceRef   Externe Referenz (z.B. Artikel-ID als String)
     * @param ?string             $sourceHash  SHA-256 des Quell-Sprach-Wertes, hex
     * @param list<string>        $tags        Freie Tag-Liste
     * @param ?string             $notes       Übersetzer-Notiz
     * @param ?DateTimeImmutable  $createdAt   NULL, wenn noch nicht in DB
     * @param ?DateTimeImmutable  $updatedAt   NULL, wenn noch nicht in DB
     */
    public function __construct(
        public ?int $id,
        public string $namespace,
        public string $unitKey,
        public string $context = '',
        public ?SourceType $sourceType = null,
        public ?string $sourceRef = null,
        public ?string $sourceHash = null,
        public array $tags = [],
        public ?string $notes = null,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $updatedAt = null,
    ) {}

    /**
     * Normalisiert einen Bereich-/Context-Eingabewert: Whitespace UND
     * führende/abschließende Punkte fallen weg.
     *
     * Der Lookup baut den Platzhalter als `context . '.' . unit_key`
     * (siehe WildcardLookupService). Ohne dieses Trimmen erzeugt die Eingabe
     * „test.mt." (mit Schlusspunkt) den kaputten Platzhalter
     * `{{ test.mt..button }}` statt des gewollten `{{ test.mt.button }}`.
     *
     * Punkte INNERHALB des Bereichs bleiben erhalten — sie sind die
     * beabsichtigte Sub-Hierarchie (z.B. „page.about").
     */
    public static function normalizeContext(string $raw): string
    {
        return trim($raw, " \t\n\r\0\x0B.");
    }
}
