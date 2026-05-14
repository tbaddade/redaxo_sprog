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
 * Hinweis zur Verhaltens-Abweichung gegenüber v1:
 *   v1 hatte ein per-row status-Flag (0/1) und renderte nur status=1. v2 hat
 *   diese Semantik nicht — der AbbreviationMigrator ignoriert das Flag derzeit.
 *   Sobald migrierte Daten im Spiel sind, werden auch ehemals inaktive
 *   Abbreviations im Frontend gerendert. Wird in der Migrations-UI markiert.
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
        if ([] === $map) {
            $map = $this->loadFromV1($clangId);
        }

        return $this->cacheByClang[$clangId] = $map;
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
                   AND t.status <> :missing
                   AND t.value <> \'\'',
                [
                    'ns'      => self::NAMESPACE_ABBREVIATION,
                    'clang'   => $clangId,
                    'missing' => Status::Missing->value,
                ],
            );
        } catch (rex_sql_exception) {
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            $key   = (string) $row['unit_key'];
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
            $key   = (string) $row['abbreviation'];
            $value = (string) $row['text'];
            if ('' === $key || '' === trim($value)) {
                continue;
            }
            $map[$key] = $value;
        }

        return $map;
    }
}
