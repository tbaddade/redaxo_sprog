<?php

declare(strict_types=1);

namespace Sprog\Service;

use rex;
use rex_config;
use rex_sql;
use rex_sql_exception;
use Sprog\Cache\TranslationCacheInvalidator;
use Sprog\Enum\Status;

use function is_array;

/**
 * Frontend-Lookup für Wildcards.
 *
 * Read-Pfad der v2-Migration:
 *   1. Lookup in den v2-Tabellen (sprog_unit + sprog_translation).
 *   2. Wenn dort nichts liegt (z.B. Migration noch nicht durchgelaufen,
 *      v2-Tabellen existieren noch nicht), Fallback in die v1-Tabelle
 *      (rex_sprog_wildcard). Bestandsinstallationen rendern weiter, auch
 *      ohne dass Schritt-für-Schritt-Migration schon abgeschlossen ist.
 *
 * Caching pro Request:
 *   Die Map "wildcard => replacement" pro effektiver clang_id wird in
 *   einer Instanz-Property vorgehalten (instanz-lokal, kein Service-
 *   Singleton — der Caller, typischerweise Sprog\Compat\Wildcard, hält
 *   request-weit genau eine Instanz). So macht parse() bei 30 Wildcards
 *   im HTML genau einen DB-Roundtrip, statt 30. Persistenter Cache
 *   (rex_cache) folgt erst, wenn die Schreib-Pfade gegen v2 laufen
 *   und gezielte Invalidation möglich ist (eigene Tranche).
 *
 * clang_base-Mapping bleibt 1:1 zum v1-Verhalten erhalten.
 */
final class WildcardLookupService implements TranslationCacheInvalidator
{
    private const NAMESPACE_WILDCARD = 'wildcard';

    /**
     * Pro effektiver clang_id: zwei Maps.
     *   exact: Units mit context='' (= bisheriges v1-Verhalten)
     *   ctx:   Units mit context!='', indexiert nach "context.unit_key".
     *
     * @var array<int, array{exact: array<string,string>, ctx: array<string,string>}>
     */
    private array $cacheByClang = [];

    /**
     * Factory-Method analog zu TranslationService / MigrationService / MtService —
     * konsistentes DI-Pattern statt Singleton. Der Caller hält die Instanz so
     * lange er den Cache nutzen will (z.B. eine Compat\Wildcard-Klasse einmal
     * pro Request).
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * Verwirft den Request-Cache der konkreten Instanz. Hauptsächlich für
     * Tests und für Caller, die den Cache nach einem Write invalidieren
     * wollen.
     */
    public function reset(): void
    {
        $this->cacheByClang = [];
    }

    /**
     * TranslationCacheInvalidator: gezielte Invalidierung einer clang.
     * Wir invalidieren auch die "Spiegel"-clang_base-Einträge nicht
     * separat — der Cache ist nach effektiver clang_id geschlüsselt, und
     * der Caller sendet i.d.R. die originale clang_id. Daher räumen wir
     * pragmatisch sowohl die Original- als auch die effektive clang_id raus.
     */
    public function invalidateClang(int $clangId): void
    {
        unset($this->cacheByClang[$clangId]);
        $effective = $this->resolveClang($clangId);
        if ($effective !== $clangId) {
            unset($this->cacheByClang[$effective]);
        }
    }

    public function invalidateAll(): void
    {
        $this->reset();
    }

    /**
     * Resolvet die effektive Clang-ID gemäss `clang_base`-Konfiguration.
     * Eine Sprache kann konfigurativ ihre Übersetzungen aus einer anderen
     * Sprache spiegeln — dieses v1-Verhalten bleibt unverändert.
     */
    public function resolveClang(int $clangId): int
    {
        $base = rex_config::get('sprog', 'clang_base');
        if (is_array($base) && isset($base[$clangId])) {
            return (int) $base[$clangId];
        }

        return $clangId;
    }

    /**
     * Liefert die rohe Replacement zum Wildcard (ohne nl2br, ohne Filter).
     * Der Caller (Sprog\Wildcard) wendet danach Filter / replace() an.
     *
     * Lookup-Reihenfolge:
     *   1. Exact match (context=''):   `{{ test.mt.button }}` matched
     *      eine v1-/migrierte Unit mit unit_key='test.mt.button' direkt.
     *   2. Context-Match:              `{{ page.about.title }}` matched
     *      eine Unit (context='page.about', key='title').
     *
     * Damit bleiben bestehende Punkt-Keys aus v1 unangetastet, neue
     * Context-Splits funktionieren parallel.
     *
     * NULL bedeutet "kein Treffer" — auch leere Strings.
     */
    public function findOne(string $wildcard, int $clangId): ?string
    {
        $key = trim($wildcard);
        if ('' === $key) {
            return null;
        }

        $maps = $this->mapsForClang($clangId);

        // Schritt 1: Exact match (context='')
        if (isset($maps['exact'][$key])) {
            return $maps['exact'][$key];
        }

        // Schritt 2: Context-Match — wird im loadFromV2 schon zum "context.unit_key"-
        // String konkateniert in die ctx-Map gelegt, sodass ein einziger
        // Hash-Lookup reicht.
        return $maps['ctx'][$key] ?? null;
    }

    /**
     * Flache Map "key => replacement" für die Sprache; vereint exact-Map und
     * Context-Map (exact gewinnt bei Konflikt). Wird von parse() für die
     * Batch-Resolution mehrerer Wildcards pro String genutzt.
     *
     * @return array<string, string>
     */
    public function allForClang(int $clangId): array
    {
        $maps = $this->mapsForClang($clangId);

        // ctx zuerst, exact danach — exact überschreibt damit alle Konflikte.
        return $maps['ctx'] + $maps['exact'];
    }

    /**
     * @return array{exact: array<string,string>, ctx: array<string,string>}
     */
    private function mapsForClang(int $clangId): array
    {
        $effective = $this->resolveClang($clangId);
        if (isset($this->cacheByClang[$effective])) {
            return $this->cacheByClang[$effective];
        }

        $maps = $this->loadFromV2($effective);
        if ([] === $maps['exact'] && [] === $maps['ctx']) {
            // v2 hat nichts gefunden (Tabellen fehlen oder leer) → v1-Fallback.
            // v1 kennt keinen Context, alles wandert in die exact-Map.
            $maps = ['exact' => $this->loadFromV1($effective), 'ctx' => []];
        }

        $this->cacheByClang[$effective] = $maps;

        return $maps;
    }

    /**
     * @return array{exact: array<string,string>, ctx: array<string,string>}
     */
    private function loadFromV2(int $clangId): array
    {
        try {
            $rows = rex_sql::factory()->getArray(
                'SELECT u.context, u.unit_key, t.value
                 FROM ' . rex::getTable('sprog_unit') . ' u
                 INNER JOIN ' . rex::getTable('sprog_translation') . ' t
                    ON t.unit_id = u.id
                 WHERE u.namespace = :ns
                   AND t.clang_id = :clang
                   AND t.status <> :missing
                   AND t.value <> \'\'',
                [
                    'ns' => self::NAMESPACE_WILDCARD,
                    'clang' => $clangId,
                    'missing' => Status::Missing->value,
                ],
            );
        } catch (rex_sql_exception) {
            // v2-Tabellen noch nicht angelegt (z.B. install.php nicht gelaufen).
            return ['exact' => [], 'ctx' => []];
        }

        $exact = [];
        $ctx = [];
        foreach ($rows as $row) {
            $key = (string) $row['unit_key'];
            $context = (string) ($row['context'] ?? '');
            $value = (string) $row['value'];
            if ('' === $key || '' === trim($value)) {
                continue;
            }

            if ('' === $context) {
                $exact[$key] = $value;
                continue;
            }

            // Context-Units werden auf "context.unit_key" konkateniert. Bei
            // Konflikt (zwei Context-Units, die denselben Template-Tag matchen)
            // gewinnt der zuerst eingesetzte; UI-seitig findet ConflictService
            // beide und zeigt die Warnung.
            $fullKey = $context . '.' . $key;
            if (!isset($ctx[$fullKey])) {
                $ctx[$fullKey] = $value;
            }
        }

        return ['exact' => $exact, 'ctx' => $ctx];
    }

    /**
     * @return array<string, string>
     */
    private function loadFromV1(int $clangId): array
    {
        try {
            $rows = rex_sql::factory()->getArray(
                'SELECT wildcard, `replace` FROM ' . rex::getTable('sprog_wildcard') . '
                 WHERE clang_id = :clang',
                ['clang' => $clangId],
            );
        } catch (rex_sql_exception) {
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            $key = (string) $row['wildcard'];
            $value = (string) $row['replace'];
            if ('' === $key || '' === trim($value)) {
                continue;
            }
            $map[$key] = $value;
        }

        return $map;
    }
}
