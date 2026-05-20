<?php

declare(strict_types=1);

namespace Sprog\Service;

use rex;
use rex_sql;
use rex_sql_exception;
use Sprog\Enum\Status;
use Sprog\Model\CoverageStat;

/**
 * Lese-Service für Coverage-Aggregate aus dem v2-Schema.
 *
 * Eine einzige GROUP-BY-Query liefert das gesamte Material; die Service-
 * Methoden gruppieren das Rohergebnis nur noch. So bleibt das Dashboard
 * auch bei vielen Sprachen × Namespaces × Status-Werten performant.
 *
 * Bewusst kein Caching hier: Coverage-Werte sollen aktuell sein, sobald
 * jemand eine Übersetzung speichert. Persistenter Cache mit gezielter
 * Invalidation kommt später (eigene Cache-Tranche).
 */
final class CoverageService
{
    public static function create(): self
    {
        return new self();
    }

    /**
     * @return array<int, array<string, CoverageStat>> clangId => (namespace => CoverageStat)
     */
    public function overview(): array
    {
        $rows = $this->loadAggregates();
        if ([] === $rows) {
            return [];
        }

        // [clang][namespace][status_value] => count
        $aggregate = [];
        $validStatus = array_flip(Status::values());

        foreach ($rows as $row) {
            $clang = (int) ($row['clang_id'] ?? 0);
            $namespace = (string) ($row['namespace'] ?? '');
            $status = (string) ($row['status'] ?? '');
            $count = (int) ($row['cnt'] ?? 0);

            // Unbekannte Status-Werte werden ignoriert statt eine ValueError zu
            // werfen — Dashboard soll auch bei Daten-Korruption etwas anzeigen.
            if ('' === $namespace || !isset($validStatus[$status])) {
                continue;
            }

            $aggregate[$clang][$namespace][$status] = ($aggregate[$clang][$namespace][$status] ?? 0) + $count;
        }

        $result = [];
        foreach ($aggregate as $clang => $byNamespace) {
            foreach ($byNamespace as $namespace => $countByStatus) {
                $result[$clang][$namespace] = new CoverageStat($clang, $namespace, $countByStatus);
            }
        }

        ksort($result);

        return $result;
    }

    /**
     * Aggregat pro Sprache: Summen über alle Namespaces.
     *
     * @return array<int, CoverageStat> clangId => CoverageStat (namespace='*' )
     */
    public function totalsByClang(): array
    {
        $totals = [];
        foreach ($this->overview() as $clang => $byNamespace) {
            $merged = [];
            foreach ($byNamespace as $stat) {
                foreach ($stat->countByStatus as $statusValue => $count) {
                    $merged[$statusValue] = ($merged[$statusValue] ?? 0) + $count;
                }
            }
            $totals[$clang] = new CoverageStat($clang, '*', $merged);
        }

        return $totals;
    }

    /**
     * Anzahl der Units in der DB; gibt 0 zurück, wenn die v2-Tabellen
     * noch nicht existieren (frische Installation, Migration noch nicht
     * gelaufen).
     */
    public function totalUnits(): int
    {
        try {
            $rows = rex_sql::factory()->getArray(
                'SELECT COUNT(*) AS cnt FROM ' . rex::getTable('sprog_unit'),
            );

            return (int) ($rows[0]['cnt'] ?? 0);
        } catch (rex_sql_exception) {
            return 0;
        }
    }

    /**
     * @return list<array{namespace: string, clang_id: int, status: string, cnt: int}>
     */
    private function loadAggregates(): array
    {
        try {
            $rows = rex_sql::factory()->getArray(
                'SELECT u.namespace, t.clang_id, t.status, COUNT(*) AS cnt
                 FROM ' . rex::getTable('sprog_unit') . ' u
                 INNER JOIN ' . rex::getTable('sprog_translation') . ' t
                    ON t.unit_id = u.id
                 GROUP BY u.namespace, t.clang_id, t.status',
            );
        } catch (rex_sql_exception) {
            return [];
        }

        // rex_sql::getArray() typisiert als array<string, scalar|null>; das Query
        // garantiert die Spaltentypen, daher hier explizite Normalisierung.
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'namespace' => (string) $row['namespace'],
                'clang_id' => (int) $row['clang_id'],
                'status' => (string) $row['status'],
                'cnt' => (int) $row['cnt'],
            ];
        }
        return $result;
    }
}
