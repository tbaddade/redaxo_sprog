<?php

declare(strict_types=1);

namespace Sprog\Schema;

use rex;
use rex_sql_table;

/**
 * v1-Bestandstabellen (Wildcards / Abbreviations / Foreignwords).
 *
 * v2 legt diese Tabellen NICHT mehr an — existieren sie (Update von 1.x), liest
 * nur noch die einmalige Migration sie (Migratoren mit SHOW TABLES-Guard); danach
 * bleiben sie als Sicherung liegen. Das Frontend liest ausschließlich v2. Daher
 * bleibt hier nur noch das Aufräumen beim Uninstall.
 */
final class V1Schema
{
    /**
     * Droppt die v1-Tabellen. Wird beim Uninstall aus uninstall.php aufgerufen.
     * rex_sql_table::drop() ist idempotent (DROP TABLE IF EXISTS), kann also
     * bedenkenlos auf einem System ohne v1-Tabellen laufen.
     */
    public static function drop(): void
    {
        rex_sql_table::get(rex::getTable('sprog_wildcard'))->drop();
        rex_sql_table::get(rex::getTable('sprog_abbreviation'))->drop();
        rex_sql_table::get(rex::getTable('sprog_foreignword'))->drop();
    }
}
