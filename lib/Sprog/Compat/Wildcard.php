<?php

/**
 * This file is part of the Sprog package.
 *
 * @author (c) Thomas Blum <thomas@addoff.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sprog\Compat;

use rex;
use rex_addon;
use rex_clang;
use rex_config;
use rex_fragment;
use rex_plugin;
use rex_sql;
use rex_url;
use Sprog\Cache\CacheInvalidationBus;
use Sprog\Service\WildcardLookupService;

use function count;

use const PREG_SET_ORDER;

class Wildcard
{
    /**
     * Statischer Holder für die LookupService-Instanz innerhalb eines Requests.
     * Der Service selbst nutzt kein Singleton-Pattern mehr — sein Cache lebt
     * in einer normalen Instanz. Damit alle Aufrufe aus parse()/get() in
     * derselben Request denselben Cache treffen, hält Wildcard die Instanz
     * hier zentral. Tests können sie via `resetLookupService()` zurücksetzen.
     */
    private static ?WildcardLookupService $lookupService = null;

    private static function lookupService(): WildcardLookupService
    {
        if (null !== self::$lookupService) {
            return self::$lookupService;
        }
        self::$lookupService = WildcardLookupService::create();
        // Registrierung am Default-Bus: sobald TranslationService einen
        // Write macht und dessen create() den Default-Bus zieht, werden alle
        // hier registrierten Lookup-Caches automatisch invalidiert.
        CacheInvalidationBus::default()->register(self::$lookupService);

        return self::$lookupService;
    }

    public static function resetLookupService(): void
    {
        self::$lookupService = null;
    }

    public static function getOpenTag(): string
    {
        return (string) rex_config::get('sprog', 'wildcard_open_tag', '{{ ');
    }

    public static function getCloseTag(): string
    {
        return (string) rex_config::get('sprog', 'wildcard_close_tag', ' }}');
    }

    public static function getRegexp(string $value = '.*?'): string
    {
        return '@(?<complete>' . preg_quote(trim(self::getOpenTag())) . '\s*(?<wildcard>' . $value . ')\s*((\|(?<filter>\s*[a-z]+)\(?(?<arguments>.*?)?\)?))?\s*' . preg_quote(trim(self::getCloseTag())) . ')@';
    }

    /**
     * Returns the replaced wildcard.
     *
     * @param string $wildcard
     * @param int    $clang_id
     *
     * @return string|false replacement string when found, false otherwise.
     *                      v1-API contract — bewusst beibehalten, weil Caller in
     *                      Bestandscode auf "if (false === Wildcard::get(...))"
     *                      prüfen.
     */
    public static function get($wildcard, $clang_id = null)
    {
        if ('' == trim($wildcard)) {
            return $wildcard;
        }

        if (!$clang_id) {
            $clang_id = rex_clang::getCurrentId();
        }

        // Lookup geht über den v2-Service: zuerst sprog_unit/sprog_translation,
        // bei Leere fällt er auf rex_sprog_wildcard zurück. clang_base-Auflösung
        // erledigt der Service selbst — daher hier nicht mehr doppelt mappen.
        $replacement = self::lookupService()->findOne((string) $wildcard, (int) $clang_id);

        if (null !== $replacement && '' !== trim((string) $replacement)) {
            return self::replace($wildcard, $replacement);
        }

        return false;
    }

    /**
     * Returns the replaced content.
     *
     * @param string $content
     * @param int    $clang_id
     *
     * @return string
     */
    public static function parse($content, $clang_id = null)
    {
        if ('' == trim($content)) {
            return $content;
        }

        preg_match_all(self::getRegexp(), $content, $matches, PREG_SET_ORDER);
        if (count($matches) < 1) {
            return $content;
        }

        if (!$clang_id) {
            $clang_id = rex_clang::getCurrentId();
        }

        // Eine Query für alle Wildcards der Sprache; v2 zuerst, sonst v1.
        // clang_base wird im Service aufgelöst.
        $wildcards = self::lookupService()->allForClang((int) $clang_id);
        if ([] === $wildcards) {
            return $content;
        }

        $filters = rex::getProperty('SPROG_FILTER', []);
        $search = [];
        $replace = [];
        foreach ($matches as $match) {
            $value = $match['complete'];
            if (isset($wildcards[$match['wildcard']])) {
                $value = $wildcards[$match['wildcard']];
            }
            if (isset($match['filter']) && isset($filters[trim($match['filter'])])) {
                $filter = $filters[trim($match['filter'])];
                $arguments = $match['arguments'] ?? '';
                $value = $filter->fire($value, $arguments);
            } else {
                $value = self::replace($match['wildcard'], $value);
            }

            $search[] = $match['complete'];
            $replace[] = $value;
        }
        return str_replace($search, $replace, $content);
    }

    /**
     * @return array<string, array{wildcard: string, url: string}>|false false wenn structure-Addon nicht verfügbar
     */
    public static function getMissingWildcards()
    {
        $wildcards = [];

        if (rex_addon::get('structure')->isAvailable() && rex_plugin::get('structure', 'content')->isAvailable()) {
            $sql = rex_sql::factory();

            // Slices der Artikel durchsuchen
            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
            $fields = [
                's.value' => range('1', '20'),
            ];

            $searchFields = [];
            $selectFields = [];
            foreach ($fields as $field => $numbers) {
                $concatFields = [];
                foreach ($numbers as $number) {
                    $concatFields[] = $field . $number;
                    $searchFields[] = $field . $number . ' RLIKE ' . $sql->escape(preg_quote(trim(self::getOpenTag())) . '.*' . preg_quote(trim(self::getCloseTag())));
                }
                $selectFields[] = 'CONCAT_WS("|", ' . implode(',', $concatFields) . ') AS subject';
            }

            $fields = $searchFields;

            $sql_query = ' SELECT       s.article_id AS id,
                                        s.clang_id,
                                        s.ctype_id,
                                        ' . implode(', ', $selectFields) . '
                            FROM        ' . rex::getTable('article_slice') . ' AS s
                                LEFT JOIN
                                        ' . rex::getTable('article') . ' AS a
                                    ON  (s.article_id = a.id AND s.clang_id = a.clang_id)
                            WHERE       ' . implode(' OR ', $fields) . '
                            ';

            $sql->setDebug(false);
            $sql->setQuery($sql_query);

            if ($sql->getRows() >= 1) {
                $items = $sql->getArray();

                foreach ($items as $item) {
                    preg_match_all(self::getRegexp(), (string) $item['subject'], $matchesSubject, PREG_SET_ORDER);

                    foreach ($matchesSubject as $match) {
                        $wildcard = (string) $match['wildcard'];
                        $wildcards[$wildcard]['wildcard'] = $wildcard;
                        $wildcards[$wildcard]['url'] = rex_url::backendController(
                            [
                                'page' => 'content/edit',
                                'article_id' => $item['id'],
                                'mode' => 'edit',
                                'clang' => $item['clang_id'],
                                'ctype' => $item['ctype_id'],
                            ],
                        );
                    }
                }
            }

            // Alle bereits angelegten Platzhalter entfernen
            if (count($wildcards)) {
                $sql = rex_sql::factory();
                $sql->setDebug(false);
                $sql->setQuery('
                    SELECT  wildcard
                    FROM    ' . rex::getTable('sprog_wildcard') . '
                    WHERE   clang_id = :clang_id',
                    ['clang_id' => rex_clang::getStartId()],
                );

                if ($sql->getRows() >= 1) {
                    $items = $sql->getArray();
                    foreach ($items as $item) {
                        $key = (string) $item['wildcard'];
                        if (isset($wildcards[$key])) {
                            unset($wildcards[$key]);
                        }
                    }
                }
            }
            return $wildcards;
        }

        return false;
    }

    public static function getMissingWildcardsAsTable(): ?string
    {
        $missingWildcards = self::getMissingWildcards();
        if (false === $missingWildcards || 0 === count($missingWildcards)) {
            return null;
        }

        $content = '';
        $content .= '
            <table class="table table-striped table-hover">
               <thead>
                   <tr>
                       <th class="rex-table-icon"></th>
                       <th>' . rex_addon::get('sprog')->i18n('wildcard') . '</th>
                       <th class="rex-table-action" colspan="2">' . rex_addon::get('sprog')->i18n('function') . '</th>
                   </tr>
               </thead>
               <tbody>
           ';

        foreach ($missingWildcards as $name => $params) {
            $content .= '
                       <tr>
                           <td class="rex-table-icon"><i class="rex-icon rex-icon-refresh"></i></td>
                           <td data-title="' . rex_addon::get('sprog')->i18n('wildcard') . '">' . $name . '</td>
                           <td class="rex-table-action"><a href="' . rex_url::currentBackendPage(['func' => 'add', 'wildcard_name' => $params['wildcard']]) . '"><i class="rex-icon rex-icon-edit"></i> ' . rex_addon::get('sprog')->i18n('function_add') . '</a></td>
                           <td class="rex-table-action"><a href="' . $params['url'] . '"><i class="rex-icon rex-icon-article"></i> ' . rex_addon::get('sprog')->i18n('wildcard_go_to_the_article') . '</a></td>
                       </tr>';
        }

        $content .= '
               </tbody>
           </table>';

        $fragment = new rex_fragment();
        $fragment->setVar('title', rex_addon::get('sprog')->i18n('wildcard_caption_missing', rex_addon::get('structure')->i18n('title_structure')), false);
        $fragment->setVar('content', $content, false);

        return (string) $fragment->parse('core/page/section.php');
    }

    /**
     * Returns the replaced wildcard.
     */
    protected static function replace(string $wildcard, string $replace): string
    {
        return nl2br($replace);
    }

    public static function checkAllLanguagesHaveAllWildcardsAndRepairIfNecessary(): void
    {
        $sql = rex_sql::factory();
        $records = $sql->getArray('SELECT * FROM ' . rex::getTable('sprog_wildcard') . ' ORDER BY id, clang_id');

        $items = [];
        foreach ($records as $record) {
            $items[(int) $record['id']][(int) $record['clang_id']] = $record;
        }

        foreach ($items as $id => $clangRecords) {
            foreach (rex_clang::getAllIds() as $clangId) {
                if (!isset($clangRecords[$clangId])) {
                    $firstClangRecord = $clangRecords[array_key_first($clangRecords)];

                    $sqlNewRecord = rex_sql::factory();
                    $sqlNewRecord->setTable(rex::getTable('sprog_wildcard'));
                    $sqlNewRecord->setValue('id', $firstClangRecord['id']);
                    $sqlNewRecord->setValue('clang_id', $clangId);
                    $sqlNewRecord->setValue('wildcard', $firstClangRecord['wildcard']);
                    $sqlNewRecord->addGlobalCreateFields();
                    $sqlNewRecord->addGlobalUpdateFields();
                    $sqlNewRecord->insert();
                }
            }
        }
    }
}
