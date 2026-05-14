<?php

declare(strict_types=1);

namespace Sprog\Service;

use DateTimeImmutable;
use rex;
use rex_clang;
use rex_sql;
use rex_sql_exception;
use Sprog\Enum\Status;
use Sprog\Model\TranslationListFilter;
use Sprog\Model\TranslationListItem;

/**
 * Listen-Query für die Translation-Inbox.
 *
 * Modell:
 *   - Pro Unit ein Item (nicht pro Translation wie zuvor).
 *   - $filter->clangId ist die ANZEIGE-Sprache (kein Filter). Sie bestimmt
 *     welche Translation als Wert-Preview + Status-Badge im Listen-Summary
 *     ausgegeben wird.
 *   - $filter->namespace + $filter->search filtern auf Unit-Ebene.
 *   - $filter->statuses filtert UNIT-WEIT: eine Unit matched, sobald
 *     mind. EINE ihrer Translations den Status hat (EXISTS-Subquery).
 *
 * Match-Detection:
 *   Wenn der Status-Filter aktiv ist und die Display-Translation den Filter
 *   nicht selbst erfüllt, wird die "Match-Sprache" mitgeladen (erste andere
 *   Sprache, deren Translation einen der Filter-Status hat). Die UI nutzt
 *   das für den Hinweis "übersetzt · +en entwurf" im Summary.
 *
 * Sicherheits-Stamm:
 *   - Alle WHERE-Parameter über named placeholders, kein String-Concat
 *     außer für LIMIT/OFFSET (dort explizit (int)-Cast — die Werte sind
 *     vom Filter validiert).
 *   - Status-Werte stammen aus Status-Enum; vor der Konstruktion ins SQL
 *     wird über $candidate->value gegangen, also nie freier String.
 *   - LIKE-Suche escapet Wildcards via rex_sql::escapeLikeWildcards().
 *   - Namespace ist im Filter regex-validiert (^[a-z0-9_.]+$/i); zusätzlich
 *     wird er hier nur als prepared param verwendet.
 */
final class TranslationListService
{
    public static function create(): self
    {
        return new self();
    }

    /**
     * @return array{
     *     items: list<TranslationListItem>,
     *     total: int,
     *     page: int,
     *     pageSize: int
     * }
     */
    public function query(TranslationListFilter $filter): array
    {
        [$unitWhereSql, $unitParams] = $this->buildUnitWhere($filter);

        $total = $this->countUnits($unitWhereSql, $unitParams);
        $items = $total > 0 ? $this->loadUnitItems($filter, $unitWhereSql, $unitParams) : [];

        return [
            'items'    => $items,
            'total'    => $total,
            'page'     => $filter->page,
            'pageSize' => $filter->pageSize,
        ];
    }

    /**
     * @param array<string, scalar> $params
     */
    private function countUnits(string $whereSql, array $params): int
    {
        try {
            $rows = rex_sql::factory()->getArray(
                'SELECT COUNT(*) AS cnt FROM ' . rex::getTable('sprog_unit') . ' u WHERE ' . $whereSql,
                $params,
            );
        } catch (rex_sql_exception) {
            // v2-Tabellen existieren noch nicht — Inbox bleibt leer.
            return 0;
        }

        return (int) ($rows[0]['cnt'] ?? 0);
    }

    /**
     * @param array<string, scalar> $params
     *
     * @return list<TranslationListItem>
     */
    private function loadUnitItems(TranslationListFilter $filter, string $whereSql, array $params): array
    {
        $unitTable        = rex::getTable('sprog_unit');
        $translationTable = rex::getTable('sprog_translation');

        // LIMIT/OFFSET via int-cast in den Query-String — siehe Hinweis in
        // TranslationRepository::findByClangAndStatus: nicht alle PDO-Treiber
        // binden Integer-Parameter korrekt an LIMIT.
        $allParams                  = $params;
        $allParams['display_clang'] = $filter->clangId;

        try {
            $rows = rex_sql::factory()->getArray(
                'SELECT
                    u.id                AS unit_id,
                    u.namespace         AS namespace,
                    u.unit_key          AS unit_key,
                    u.context           AS context,
                    u.notes             AS notes,
                    d.id                AS display_translation_id,
                    d.value             AS display_value,
                    d.status            AS display_status,
                    d.mt_provider       AS display_mt_provider,
                    d.mt_confidence     AS display_mt_confidence,
                    d.revision          AS display_revision,
                    d.updatedate        AS display_updatedate
                 FROM ' . $unitTable . ' u
                 LEFT JOIN ' . $translationTable . ' d
                    ON d.unit_id = u.id AND d.clang_id = :display_clang
                 WHERE ' . $whereSql . '
                 ORDER BY d.updatedate DESC, u.id DESC
                 LIMIT ' . (int) $filter->pageSize . ' OFFSET ' . (int) $filter->offset(),
                $allParams,
            );
        } catch (rex_sql_exception) {
            return [];
        }

        // Bulk-Match-Loading: bei aktivem Status-Filter eine Query, die für
        // alle Page-Units die erste matching Sprache (ungleich Anzeige-Sprache)
        // liefert. Spart N+1 pro Unit.
        $matchByUnit = [];
        if ([] !== $filter->statuses && [] !== $rows) {
            $unitIds = array_map(static fn (array $r) => (int) $r['unit_id'], $rows);
            $matchByUnit = $this->loadFilterMatches($unitIds, $filter->clangId, $filter->statuses);
        }

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->hydrate($row, $filter, $matchByUnit);
        }

        return $items;
    }

    /**
     * Pro Unit aus $unitIds: die erste Translation in einer Sprache != $excludeClang,
     * deren Status in $statuses liegt. Wird für den Match-Marker genutzt.
     *
     * @param list<int>    $unitIds
     * @param list<Status> $statuses
     * @return array<int, array{clang_id: int, status: string}>
     */
    private function loadFilterMatches(array $unitIds, int $excludeClang, array $statuses): array
    {
        if ([] === $unitIds || [] === $statuses) {
            return [];
        }

        // unit_id-Liste in den Query-String (alle Werte sind int-cast).
        $unitIdList = implode(',', array_map(static fn ($id) => (int) $id, $unitIds));

        $statusPlaceholders = [];
        $params             = ['exclude_clang' => $excludeClang];
        foreach ($statuses as $i => $status) {
            $key                  = 'match_status_' . $i;
            $statusPlaceholders[] = ':' . $key;
            $params[$key]         = $status->value;
        }

        try {
            $rows = rex_sql::factory()->getArray(
                'SELECT unit_id, clang_id, status
                 FROM ' . rex::getTable('sprog_translation') . '
                 WHERE unit_id IN (' . $unitIdList . ')
                   AND clang_id != :exclude_clang
                   AND status IN (' . implode(', ', $statusPlaceholders) . ')
                 ORDER BY unit_id, clang_id',
                $params,
            );
        } catch (rex_sql_exception) {
            return [];
        }

        // Erste Match-Sprache pro Unit gewinnt. Reihenfolge: clang_id ASC
        // (kommt aus ORDER BY) — beim ersten Treffer für unit_id stoppen wir.
        $byUnit = [];
        foreach ($rows as $row) {
            $unitId = (int) $row['unit_id'];
            if (!isset($byUnit[$unitId])) {
                $byUnit[$unitId] = [
                    'clang_id' => (int) $row['clang_id'],
                    'status'   => (string) $row['status'],
                ];
            }
        }

        return $byUnit;
    }

    /**
     * Baut den WHERE-Stamm + zugehörige Params aus dem Filter.
     * Bezieht sich auf die Unit-Tabelle (Alias `u`); Translation-Bedingungen
     * laufen über EXISTS-Subqueries, damit auch Units gefunden werden, deren
     * Match-Sprache nicht die Anzeige-Sprache ist.
     *
     * @return array{0: string, 1: array<string, scalar>}
     */
    private function buildUnitWhere(TranslationListFilter $filter): array
    {
        $translationTable = rex::getTable('sprog_translation');

        $where  = ['1=1'];
        $params = [];

        if (null !== $filter->namespace) {
            $where[]             = 'u.namespace = :namespace';
            $params['namespace'] = $filter->namespace;
        }

        if ([] !== $filter->statuses) {
            $placeholders = [];
            foreach ($filter->statuses as $i => $status) {
                $key            = 'status_' . $i;
                $placeholders[] = ':' . $key;
                $params[$key]   = $status->value;
            }
            // Unit-weit: mind. eine Translation der Unit hat einen der Status.
            $where[] = 'EXISTS (
                SELECT 1 FROM ' . $translationTable . ' ts
                WHERE ts.unit_id = u.id
                  AND ts.status IN (' . implode(', ', $placeholders) . ')
            )';
        }

        if (null !== $filter->search && '' !== trim($filter->search)) {
            // Wildcards im User-Input neutralisieren — sonst kann der User
            // mit '%' / '_' das Pattern ausweiten und full-table-scans
            // erzwingen oder unintendiert breitere Ergebnisse bekommen.
            $escaped = rex_sql::factory()->escapeLikeWildcards($filter->search);
            $like    = '%' . $escaped . '%';

            // Suche auf unit_key ODER (Unit-weit) auf irgendeine Translation-Value.
            $where[] = '(
                u.unit_key LIKE :search
                OR EXISTS (
                    SELECT 1 FROM ' . $translationTable . ' tv
                    WHERE tv.unit_id = u.id AND tv.value LIKE :search
                )
            )';
            $params['search'] = $like;
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * @param array<string, mixed>                                  $row
     * @param array<int, array{clang_id: int, status: string}>      $matchByUnit
     */
    private function hydrate(array $row, TranslationListFilter $filter, array $matchByUnit): TranslationListItem
    {
        $unitId        = (int) $row['unit_id'];
        $displayStatus = null !== ($row['display_status'] ?? null)
            ? Status::from((string) $row['display_status'])
            : Status::Missing;

        // Match-Marker nur setzen, wenn Filter aktiv UND Display-Status nicht
        // selbst im Filter liegt (sonst wäre die Anzeige-Sprache der Match —
        // dann kein extra Hinweis nötig, das Badge zeigt's schon).
        $matchClangId   = null;
        $matchClangCode = null;
        $matchStatus    = null;

        if ([] !== $filter->statuses && !in_array($displayStatus, $filter->statuses, true)) {
            $match = $matchByUnit[$unitId] ?? null;
            if (null !== $match) {
                $matchClangId   = $match['clang_id'];
                $matchStatus    = Status::from($match['status']);
                $clang          = rex_clang::get($matchClangId);
                $matchClangCode = null !== $clang ? $clang->getCode() : null;
            }
        }

        $notes = $row['notes'] ?? null;
        $notes = null !== $notes && '' !== $notes ? (string) $notes : null;

        return new TranslationListItem(
            unitId:               $unitId,
            namespace:            (string) $row['namespace'],
            unitKey:              (string) $row['unit_key'],
            context:              (string) ($row['context'] ?? ''),
            notes:                $notes,
            displayClangId:       $filter->clangId,
            displayTranslationId: (int) ($row['display_translation_id'] ?? 0),
            displayValue:         (string) ($row['display_value'] ?? ''),
            displayStatus:        $displayStatus,
            displayMtProvider:    isset($row['display_mt_provider']) && '' !== $row['display_mt_provider']
                ? (string) $row['display_mt_provider']
                : null,
            displayMtConfidence:  isset($row['display_mt_confidence']) && '' !== $row['display_mt_confidence']
                ? (float) $row['display_mt_confidence']
                : null,
            displayRevision:      (int) ($row['display_revision'] ?? 0),
            displayUpdatedAt:     $this->toDateTime($row['display_updatedate'] ?? null),
            matchClangId:         $matchClangId,
            matchClangCode:       $matchClangCode,
            matchStatus:          $matchStatus,
        );
    }

    private function toDateTime(mixed $value): ?DateTimeImmutable
    {
        if (null === $value || '' === $value || '0000-00-00 00:00:00' === $value) {
            return null;
        }

        return new DateTimeImmutable((string) $value);
    }
}
