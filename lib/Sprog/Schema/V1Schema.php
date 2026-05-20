<?php

declare(strict_types=1);

namespace Sprog\Schema;

use rex;
use rex_sql_column;
use rex_sql_index;
use rex_sql_table;

/**
 * v1-Schema (Wildcards / Abbreviations / Foreignwords).
 *
 * Bestandstabellen — bleiben während der gesamten v2.x-Reihe in Betrieb,
 * der v2-Code spiegelt sie in sprog_unit/sprog_translation.
 *
 * In der Vorversion stand dieser Code direkt in install.php. Hier zentralisiert,
 * damit der SchemaInstaller die v1-Tabellen auch beim Backend-Boot ensuren
 * kann, ohne install.php als Skript ausführen zu müssen.
 *
 * ensure() ist idempotent.
 */
final class V1Schema
{
    public static function ensure(): void
    {
        self::ensureWildcardTable();
        self::ensureAbbreviationTable();
        self::ensureForeignwordTable();
    }

    /**
     * Gegenstück zu ensure() — droppt die v1-Tabellen. Wird beim Uninstall
     * des Addons aus uninstall.php aufgerufen. rex_sql_table::drop() ist
     * idempotent (DROP TABLE IF EXISTS), kann also bedenkenlos auf einem
     * frischen System laufen.
     */
    public static function drop(): void
    {
        rex_sql_table::get(rex::getTable('sprog_wildcard'))->drop();
        rex_sql_table::get(rex::getTable('sprog_abbreviation'))->drop();
        rex_sql_table::get(rex::getTable('sprog_foreignword'))->drop();
    }

    private static function ensureWildcardTable(): void
    {
        rex_sql_table::get(rex::getTable('sprog_wildcard'))
            ->ensureColumn(new rex_sql_column('pid', 'int(11) unsigned', false, null, 'AUTO_INCREMENT'))
            ->setPrimaryKey('pid')
            ->ensureColumn(new rex_sql_column('id', 'int(11)'))
            ->ensureColumn(new rex_sql_column('clang_id', 'int(11)'))
            ->ensureColumn(new rex_sql_column('wildcard', 'varchar(255)'))
            ->ensureColumn(new rex_sql_column('replace', 'text'))
            ->ensureGlobalColumns()
            ->ensureColumn(new rex_sql_column('revision', 'int(11)'))
            ->ensure();
    }

    private static function ensureAbbreviationTable(): void
    {
        rex_sql_table::get(rex::getTable('sprog_abbreviation'))
            ->ensureColumn(new rex_sql_column('id', 'int(11) unsigned', false, null, 'AUTO_INCREMENT'))
            ->setPrimaryKey('id')
            ->ensureColumn(new rex_sql_column('clang_id', 'int(11)'))
            ->ensureColumn(new rex_sql_column('abbreviation', 'varchar(255)'))
            ->ensureColumn(new rex_sql_column('text', 'text'))
            ->ensureColumn(new rex_sql_column('status', 'tinyint(1)'))
            ->ensureGlobalColumns()
            ->ensureColumn(new rex_sql_column('revision', 'int(11)'))
            ->ensureIndex(new rex_sql_index('find_abbreviations', ['clang_id', 'abbreviation'], rex_sql_index::UNIQUE))
            ->ensure();
    }

    /**
     * Foreignword existiert im xong/master-Stand bereits; auf master ist die Tabelle
     * noch nicht angelegt. Wir legen sie hier idempotent an, damit v2 keine v1-Quelle
     * stillschweigend ignoriert und die spätere Migration alle drei Inhaltsarten
     * gleichbehandeln kann.
     */
    private static function ensureForeignwordTable(): void
    {
        rex_sql_table::get(rex::getTable('sprog_foreignword'))
            ->ensureColumn(new rex_sql_column('id', 'int(11) unsigned', false, null, 'AUTO_INCREMENT'))
            ->setPrimaryKey('id')
            ->ensureColumn(new rex_sql_column('clang_id', 'int(11)'))
            ->ensureColumn(new rex_sql_column('foreignword', 'varchar(255)'))
            ->ensureColumn(new rex_sql_column('lang', 'varchar(2)'))
            ->ensureColumn(new rex_sql_column('status', 'tinyint(1)'))
            ->ensureGlobalColumns()
            ->ensureColumn(new rex_sql_column('revision', 'int(11)'))
            ->ensureIndex(new rex_sql_index('find_foreignword', ['clang_id', 'foreignword'], rex_sql_index::UNIQUE))
            ->ensure();
    }
}
