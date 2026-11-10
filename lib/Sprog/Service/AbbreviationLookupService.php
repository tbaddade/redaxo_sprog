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
 * Liest ausschließlich v2 (sprog_unit/sprog_translation, namespace='abbreviation').
 * Das Frontend rendert nur `approved`-Übersetzungen (Workflow-Gate). v1's
 * per-row status-Flag (0/1) wird bei der Migration auf approved (1) bzw.
 * draft (0) abgebildet — die Aktiv/Inaktiv-Semantik bleibt also erhalten.
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

        return $this->cacheByClang[$clangId] = $this->loadFromV2($clangId);
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
}
