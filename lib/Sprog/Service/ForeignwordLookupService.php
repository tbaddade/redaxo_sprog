<?php

declare(strict_types=1);

namespace Sprog\Service;

use JsonException;
use rex;
use rex_sql;
use rex_sql_exception;
use Sprog\Cache\TranslationCacheInvalidator;
use Sprog\Enum\Status;

use function is_array;
use function is_string;
use function strlen;

use const JSON_THROW_ON_ERROR;

/**
 * Frontend-Lookup für Foreignwords.
 *
 * Read-Pfad wie bei den anderen LookupServices:
 *   1. v2 (sprog_unit/sprog_translation, namespace='foreignword')
 *   2. v1-Fallback (rex_sprog_foreignword, status=1)
 *
 * Rückgabe-Form: Map "foreignword => lang-code". lang-code kann leer sein
 * — dann markiert das Frontend das Wort nicht (es gibt kein sinnvolles
 * <span lang="…"> ohne lang-Wert).
 *
 * lang lebt in v2 auf Unit-Ebene als Tag "lang:xx". In v1 ist es per Row;
 * Migrationsstrategie siehe ForeignwordMigrator.
 */
final class ForeignwordLookupService implements TranslationCacheInvalidator
{
    private const NAMESPACE_FOREIGNWORD = 'foreignword';
    private const LANG_REGEX = '/^[a-z]{2}$/';
    private const LANG_TAG_PREFIX = 'lang:';

    /** @var array<int, array<string, string>> */
    private array $cacheByClang = [];

    /**
     * Factory-Method analog zu den anderen v2-Services. DI statt Singleton —
     * der Caller (typischerweise Sprog\Compat\Foreignword) hält die Instanz
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
     * @return array<string, string> foreignword => lang (kann '' sein, wenn kein lang gepflegt)
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
     * Existiert in v2 überhaupt eine Foreignword-Übersetzung (beliebiger
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
                ['ns' => self::NAMESPACE_FOREIGNWORD, 'clang' => $clangId],
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
                'SELECT u.unit_key, u.tags
                 FROM ' . rex::getTable('sprog_unit') . ' u
                 INNER JOIN ' . rex::getTable('sprog_translation') . ' t
                    ON t.unit_id = u.id
                 WHERE u.namespace = :ns
                   AND t.clang_id = :clang
                   AND t.status = :approved',
                [
                    'ns' => self::NAMESPACE_FOREIGNWORD,
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
            if ('' === $key) {
                continue;
            }
            $map[$key] = $this->extractLangFromTags((string) ($row['tags'] ?? ''));
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
                'SELECT foreignword, lang FROM ' . rex::getTable('sprog_foreignword') . '
                 WHERE clang_id = :clang AND status = 1',
                ['clang' => $clangId],
            );
        } catch (rex_sql_exception) {
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            $key = (string) $row['foreignword'];
            if ('' === $key) {
                continue;
            }
            $map[$key] = $this->normalizeLang((string) ($row['lang'] ?? ''));
        }

        return $map;
    }

    private function extractLangFromTags(string $tagsJson): string
    {
        if ('' === $tagsJson) {
            return '';
        }

        try {
            $tags = json_decode($tagsJson, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Kaputte tags-Spalte — kein lang, aber wir lassen das Wort
            // weiter im Lookup (nur die Markierung fehlt halt).
            return '';
        }

        if (!is_array($tags)) {
            return '';
        }

        foreach ($tags as $tag) {
            if (!is_string($tag) || !str_starts_with($tag, self::LANG_TAG_PREFIX)) {
                continue;
            }

            return $this->normalizeLang(substr($tag, strlen(self::LANG_TAG_PREFIX)));
        }

        return '';
    }

    private function normalizeLang(string $raw): string
    {
        $candidate = strtolower(trim($raw));

        return 1 === preg_match(self::LANG_REGEX, $candidate) ? $candidate : '';
    }
}
