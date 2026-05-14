<?php

declare(strict_types=1);

namespace Sprog\Service;

use rex;
use rex_sql;
use rex_sql_exception;

/**
 * Erkennt Konflikte zwischen Wildcard-Units, die im Frontend auf
 * denselben Template-Tag aufgelöst würden.
 *
 * Zwei Konflikt-Typen:
 *
 *   shadowed-by-exact: eine Unit mit context!='' wird durch eine globale
 *     (context='') Unit mit identischem Punkt-konkatenierten Key
 *     überschattet. Der Lookup nimmt immer den exact match — die Context-
 *     Unit ist effektiv tot und wird im Output nie verwendet.
 *
 *   multiple-contexts: zwei oder mehr Context-Units konkurrieren auf
 *     denselben Template-Tag (z.B. (page.about, title) vs (page, about.title)).
 *     Im Lookup gewinnt einer von beiden (Reihenfolge der DB-Rows), die
 *     anderen werden ignoriert.
 *
 * In beiden Fällen kann der Redakteur nicht aus dem Backend allein
 * erkennen, warum ein Wildcard "nicht greift" — daher die Warnung in
 * der Inbox.
 */
final class WildcardConflictService
{
    public static function create(): self
    {
        return new self();
    }

    /**
     * Liefert pro betroffener unit_id einen menschenlesbaren Konflikt-Text.
     * Bei Tabellen-Fehlern (Schema noch nicht migriert) leeres Array.
     *
     * @return array<int, string>  unit_id => Hinweis-Text
     */
    public function findConflictsByUnitId(): array
    {
        $unitTable = rex::getTable('sprog_unit');

        try {
            $rows = rex_sql::factory()->getArray(
                "SELECT id, namespace, context, unit_key
                 FROM {$unitTable}
                 WHERE namespace = 'wildcard'",
            );
        } catch (rex_sql_exception) {
            return [];
        }

        // Erst alle Wildcard-Units in eine Form bringen, in der wir nach
        // dem effektiven Template-Tag gruppieren können.
        // tagToUnits[$templateTag] = [['id' => int, 'context' => string, 'key' => string], ...]
        $tagToUnits = [];
        foreach ($rows as $row) {
            $id      = (int) $row['id'];
            $context = (string) ($row['context'] ?? '');
            $key     = (string) $row['unit_key'];

            // Effektiver Template-Tag, auf den der Frontend-Parser dieses
            // Eintrag matchen würde.
            $tag = '' === $context ? $key : $context . '.' . $key;

            $tagToUnits[$tag][] = [
                'id'      => $id,
                'context' => $context,
                'key'     => $key,
            ];
        }

        $conflicts = [];
        foreach ($tagToUnits as $tag => $group) {
            if (count($group) < 2) {
                continue;
            }

            // Trennen: gibt es einen exact-Match (context='') in der Gruppe?
            $exactEntries   = array_values(array_filter($group, static fn (array $e) => '' === $e['context']));
            $contextEntries = array_values(array_filter($group, static fn (array $e) => '' !== $e['context']));

            // Typ A: shadowed-by-exact — exact-Match überschattet eine oder
            // mehrere Context-Units.
            if ([] !== $exactEntries && [] !== $contextEntries) {
                $exactId = $exactEntries[0]['id'];
                foreach ($contextEntries as $ctxEntry) {
                    $conflicts[$ctxEntry['id']] = sprintf(
                        '%s wird im Frontend nicht greifen: der globale Schlüssel "%s" ohne Bereich gewinnt (Einheit #%d).',
                        $tag,
                        $tag,
                        $exactId,
                    );
                }
                // Der globale Eintrag selbst kann normal verwendet werden;
                // er ist nicht überschattet, sondern überschattet andere.
                // Trotzdem markieren wir ihn als „beachte mich" — bei
                // mehreren Treffern soll der Admin entscheiden.
                $shadowedIds = array_map(static fn (array $e) => (int) $e['id'], $contextEntries);
                $conflicts[$exactId] = sprintf(
                    'Der globale Schlüssel "%s" überschattet %d Einheit(en) mit Bereich. Diese werden im Frontend ignoriert.',
                    $tag,
                    count($shadowedIds),
                );
                continue;
            }

            // Typ B: multiple-contexts — mehrere Context-Units konkurrieren.
            // Wir markieren alle, weil nicht eindeutig ist, welcher gewinnt.
            if (count($contextEntries) >= 2) {
                $ids = array_map(static fn (array $e) => (int) $e['id'], $contextEntries);
                $hint = sprintf(
                    'Mehrere Bereich-Einheiten konkurrieren auf "%s": #%s. Im Frontend gewinnt nur einer, die anderen sind effektiv tot.',
                    $tag,
                    implode(', #', $ids),
                );
                foreach ($ids as $id) {
                    $conflicts[$id] = $hint;
                }
            }
        }

        return $conflicts;
    }
}
