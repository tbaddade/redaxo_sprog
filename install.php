<?php

declare(strict_types=1);
use Sprog\Schema\V2Schema;

/*
 * Sprog v2-Schema-Setup.
 *
 *   V2Schema::ensure()  → sprog_unit, sprog_translation, sprog_glossary,
 *                         sprog_tm, sprog_activity, sprog_translation_history.
 *                         Idempotent (rex_sql_table-API, legt nur fehlende
 *                         Spalten/Indizes an).
 *
 * REDAXO führt install.php beim Update aus dem Temp-Verzeichnis (.new.<addon>)
 * aus, BEVOR die Dateien am Zielort liegen — der Autoloader kennt die neuen
 * Klassen dort noch nicht. V2Schema ist self-contained (nur Core-Klassen), daher
 * genügt ein gezieltes require_once dieser einen Datei, statt den ganzen lib-Baum
 * zu registrieren. Bei einer Erstinstallation ist das require_once ein No-Op
 * (Datei bereits via Autoloader geladen).
 *
 * Die v1-Bestandstabellen (sprog_wildcard/_abbreviation/_foreignword) werden hier
 * NICHT angelegt: Existieren sie (Update von 1.x), liest der Frontend-Fallback sie
 * weiter und die Migration überführt sie nach v2; fehlen sie (Neuinstallation),
 * gehen Migratoren (SHOW TABLES-Guard) und Lookup-Services (Fang von
 * rex_sql_exception) sauber leer aus.
 *
 * Die v1 → v2 Datenmigration läuft NICHT hier, sondern manuell über
 * „Datenpflege → Migration". Nach einem Update leitet Sprog Admins dorthin, solange
 * eine Migration aussteht (s. boot.php) — so gibt es keine stillen Timeouts beim
 * Install-Klick, und der Fortschritt bleibt sichtbar/retrybar.
 */
require_once __DIR__ . '/lib/Sprog/Schema/V2Schema.php';

V2Schema::ensure();
