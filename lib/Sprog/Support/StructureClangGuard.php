<?php

/**
 * This file is part of the Sprog package.
 *
 * @author (c) Thomas Blum <thomas@addoff.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Sprog\Support;

use rex_addon;
use rex_clang;
use rex_config;
use rex_escape;
use rex_extension;
use rex_extension_point;
use rex_yrewrite;
use rex_yrewrite_domain;

use function count;
use function in_array;
use function is_array;

/**
 * Struktur-Sprachfilter (DEV-Opt-in): Blendet in der Struktur (Kategoriebaum
 * und Content-Maske) die Sprach-Buttons aus, die die yrewrite-Domain der
 * aktuellen Kategorie/des Artikels nicht bedient.
 *
 * Aktivierung ausschließlich per Code/Config (kein Editor-UI):
 *   rex_config::set('sprog', 'structure_clang_filter', true);
 *
 * Die erlaubten Sprachen werden aus der yrewrite-Domain abgeleitet
 * ({@see rex_yrewrite_domain::getClangs()}). Die Default-Domain bedient immer
 * alle Sprachen → dort keine Einschränkung. Über den Extension Point
 * `SPROG_STRUCTURE_CLANGS` kann der DEV die Menge je Kontext überschreiben
 * (Subject = int[]|null, Params = context_id, clang).
 *
 * Das eigentliche Ausblenden übernimmt assets/js/sprog.structureclang.js anhand
 * des von {@see dataSpan()} eingebetteten data-Elements.
 */
final class StructureClangGuard
{
    /** Nur aktiv, wenn per Config aktiviert UND yrewrite verfügbar. */
    public static function isEnabled(): bool
    {
        return (bool) rex_config::get('sprog', 'structure_clang_filter', false)
            && rex_addon::get('yrewrite')->isAvailable();
    }

    /**
     * Verstecktes data-Element mit den erlaubten Sprach-IDs für das JS. Leerer
     * String, wenn keine Einschränkung greift (JS macht dann alle Sprachen wieder
     * sichtbar).
     */
    public static function dataSpan(int $contextId, int $clang): string
    {
        $allowed = self::allowedClangs($contextId, $clang);
        if (null === $allowed) {
            return '';
        }

        return '<span id="sprog-structure-clangs" hidden'
            . ' data-clangs="' . rex_escape(implode(',', $allowed)) . '"'
            . ' data-current="' . $clang . '"></span>';
    }

    /**
     * Erlaubte Sprach-IDs für einen Kontext (Kategorie- oder Artikel-ID) oder
     * `null` (keine Einschränkung). Die aktuell aktive Sprache ist immer enthalten,
     * damit sich niemand aussperrt.
     *
     * @return list<int>|null
     */
    public static function allowedClangs(int $contextId, int $clang): ?array
    {
        $allowed = self::resolveFromDomain($contextId);

        // DEV-Override: Subject ist die berechnete Menge (int[]|null), der DEV
        // kann sie ersetzen. null = keine Einschränkung.
        $allowed = rex_extension::registerPoint(new rex_extension_point('SPROG_STRUCTURE_CLANGS', $allowed, [
            'context_id' => $contextId,
            'clang' => $clang,
        ]));

        if (!is_array($allowed)) {
            return null;
        }

        $allowed = array_values(array_unique(array_map('intval', $allowed)));
        if (!in_array($clang, $allowed, true)) {
            $allowed[] = $clang;
        }

        return $allowed;
    }

    /**
     * Erlaubte Sprachen strikt aus der yrewrite-Domain des Kontexts. `null`, wenn
     * keine Einschränkung greift (Wurzel, keine Domain, oder Domain bedient alle
     * Sprachen wie die Default-Domain).
     *
     * @return list<int>|null
     */
    private static function resolveFromDomain(int $contextId): ?array
    {
        if ($contextId <= 0) {
            return null;
        }

        $domain = self::resolveDomain($contextId);
        if (null === $domain) {
            return null;
        }

        $clangs = array_map('intval', $domain->getClangs());
        // Domain bedient alle Sprachen (z. B. Default-Domain) → keine Einschränkung.
        if (0 === count(array_diff(rex_clang::getAllIds(), $clangs))) {
            return null;
        }

        return array_values($clangs);
    }

    /**
     * Die Domain, zu der eine Kategorie/ein Artikel gehört. Bevorzugt eine
     * spezifische (Nicht-Default-)Domain, die den Kontext in irgendeiner Sprache
     * bedient — so wird die Domain auch gefunden, wenn die aktuelle Sprache selbst
     * nicht Teil der Domain ist. Sonst Fallback auf die Default-Domain.
     */
    private static function resolveDomain(int $contextId): ?rex_yrewrite_domain
    {
        foreach (rex_clang::getAllIds() as $clangId) {
            $domain = rex_yrewrite::getDomainByArticleId($contextId, $clangId);
            if ($domain instanceof rex_yrewrite_domain && 'default' !== $domain->getName()) {
                return $domain;
            }
        }

        $domain = rex_yrewrite::getDomainByArticleId($contextId, rex_clang::getCurrentId());

        return $domain instanceof rex_yrewrite_domain ? $domain : null;
    }
}
