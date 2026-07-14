<?php

declare(strict_types=1);

namespace Sprog\Service;

use rex;
use rex_sql;
use rex_sql_exception;
use Sprog\Cache\TranslationCacheInvalidator;
use Sprog\Enum\Status;

/**
 * Frontend-Lookup für Abbreviations.
 *
 * Read-Pfad wie beim WildcardLookupService:
 *   1. v2 (sprog_unit/sprog_translation, namespace='abbreviation')
 *   2. v1-Fallback (rex_sprog_abbreviation, status=1) für nicht migrierte Bestände
 *
 * Das Frontend rendert nur `approved`-Übersetzungen (Workflow-Gate). v1's
 * per-row status-Flag (0/1) wird bei der Migration auf approved (1) bzw.
 * draft (0) abgebildet — die Aktiv/Inaktiv-Semantik bleibt also erhalten.
 * Der v1-Fallback (status=1) greift nur, wenn v2 für die Sprache noch gar
 * keine Zeile hat (= nicht migriert).
 */
final class AbbreviationLookupService implements TranslationCacheInvalidator
{
    private const NAMESPACE_ABBREVIATION = 'abbreviation';

    /** @var array<int, array<string, string>> */
    private array $cacheByClang = [];

    /**
     * Factory-Method analog zu den anderen v2-Services. DI statt Singleton —
     * der Caller (typischerweise Sprog\Compat\Abbreviation) hält die Instanz
     * so lange er den Request-Cache nutzt.
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * Verwirft den instanz-eigenen Request-Cache. Für Tests und für Caller,
     * die nach einem Write die Lookups invalidieren wollen.
     */
    public function reset(): void
    {
        $this->cacheByClang = [];
    }

    public function invalidateClang(int $clangId): void
    {
        unset($this->cacheByClang[$clangId]);
    }

    public function invalidateAll(): void
    {
        $this->reset();
    }

    /**
     * @return array<string, string> abbreviation => explanation
     */
    public function allForClang(int $clangId): array
    {
        if (isset($this->cacheByClang[$clangId])) {
            return $this->cacheByClang[$clangId];
        }

        $map = $this->loadFromV2($clangId);
        // v1-Fallback nur, wenn v2 für diese Sprache GAR KEINE Zeile hat (= noch
        // nicht migriert). Gibt es v2-Zeilen, aber keine approved, rendert
        // bewusst nichts — statt alte v1-Daten wieder hervorzuholen.
        if ([] === $map && !$this->v2HasRowsForClang($clangId)) {
            $map = $this->loadFromV1($clangId);
        }

        return $this->cacheByClang[$clangId] = $map;
    }

    /**
     * Existiert in v2 überhaupt eine Abbreviation-Übersetzung (beliebiger
     * Status) für diese Sprache? Unterscheidet "noch nicht migriert"
     * (→ v1-Fallback) von "migriert, aber nichts approved" (→ nichts rendern).
     */
    private function v2HasRowsForClang(int $clangId): bool
    {
        try {
            $rows = rex_sql::factory()->getArray(
                'SELECT 1
                 FROM ' . rex::getTable('sprog_unit') . ' u
                 INNER JOIN ' . rex::getTable('sprog_translation') . ' t
                    ON t.unit_id = u.id
                 WHERE u.namespace = :ns
                   AND t.clang_id = :clang
                 LIMIT 1',
                ['ns' => self::NAMESPACE_ABBREVIATION, 'clang' => $clangId],
            );
        } catch (rex_sql_exception) {
            return false;
        }

        return [] !== $rows;
    }

    /**
     * @return array<string, string>
     */
    private function loadFromV2(int $clangId): array
    {
        try {
            $rows = rex_sql::factory()->getArray(
                'SELECT u.unit_key, t.value
                 FROM ' . rex::getTable('sprog_unit') . ' u
                 INNER JOIN ' . rex::getTable('sprog_translation') . ' t
                    ON t.unit_id = u.id
                 WHERE u.namespace = :ns
                   AND t.clang_id = :clang
                   AND t.status = :approved
                   AND t.value <> \'\'',
                [
                    'ns' => self::NAMESPACE_ABBREVIATION,
                    'clang' => $clangId,
                    'approved' => Status::Approved->value,
                ],
            );
        } catch (rex_sql_exception) {
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            $key = (string) $row['unit_key'];
            $value = (string) $row['value'];
            if ('' === $key || '' === trim($value)) {
                continue;
            }
            $map[$key] = $value;
        }

        return $map;
    }

    /**
     * @return array<string, string>
     */
    private function loadFromV1(int $clangId): array
    {
        try {
            $rows = rex_sql::factory()->getArray(
                'SELECT abbreviation, text FROM ' . rex::getTable('sprog_abbreviation') . '
                 WHERE clang_id = :clang AND status = 1',
                ['clang' => $clangId],
            );
        } catch (rex_sql_exception) {
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            $key = (string) $row['abbreviation'];
            $value = (string) $row['text'];
            if ('' === $key || '' === trim($value)) {
                continue;
            }
            $map[$key] = $value;
        }

        return $map;
    }
}
