<?php

declare(strict_types=1);
use Sprog\Schema\V1Schema;
use Sprog\Schema\V2Schema;
use Sprog\Service\MigrationService;

/*
 * Sprog Schema-Setup.
 *
 * Beide Schritte sind idempotent und basieren auf der rex_sql_table-API,
 * die nur fehlende Spalten / Indizes anlegt.
 *
 *   V1Schema::ensure()  → sprog_wildcard, sprog_abbreviation, sprog_foreignword
 *                         Bestandstabellen, bleiben während der gesamten
 *                         v2.x-Reihe unverändert in Betrieb.
 *
 *   V2Schema::ensure()  → sprog_unit, sprog_translation, sprog_glossary,
 *                         sprog_tm, sprog_activity.
 *                         Neu in v2; werden parallel zu v1 betrieben, bis
 *                         v3 die v1-Tabellen abkündigt.
 *
 * Die DDL liegt zentral in den beiden Klassen, sodass auch andere Aufrufer
 * (Console-Command, Auto-Setup, Test-Bootstrap) sie ohne Skript-Include
 * verwenden können.
 */

V1Schema::ensure();
V2Schema::ensure();

/*
 * Automatische v1 → v2 Datenmigration bei Installation/Update.
 *
 * Damit deployte Instanzen sich selbst migrieren, statt dass ein Admin die
 * Datenpflege-Seite öffnen muss. Einmalig gesteuert über ein Flag: nach dem
 * ersten erfolgreichen Durchlauf setzen wir `migration_autorun_done`, sodass
 * weitere Deploys den (idempotenten, aber unnötigen) Rescan überspringen.
 *
 * Ein Fehler darf die Installation NICHT abbrechen — sonst könnte ein
 * Daten-Edge-Case das Addon unbrauchbar machen. Deshalb Try/Catch: der Fehler
 * wird geloggt und als installmsg gezeigt, die manuelle Migrations-Seite bleibt
 * als Retry.
 */
if (!rex_config::get('sprog', 'migration_autorun_done', false)) {
    try {
        set_time_limit(0);
        $migrationState = MigrationService::create()->migrateAll();

        if ($migrationState->isComplete()) {
            // Nur bei vollständigem Durchlauf als erledigt markieren — sonst
            // versucht es der nächste Deploy erneut, statt eine halbfertige
            // Migration dauerhaft als "done" zu verbuchen.
            rex_config::set('sprog', 'migration_autorun_done', true);
        } else {
            $this->setProperty('installmsg', rex_i18n::msg('sprog_migration_autorun_incomplete'));
        }
    } catch (Throwable $migrationError) {
        rex_logger::logException($migrationError);
        $this->setProperty('installmsg', rex_i18n::msg('sprog_migration_autorun_failed', $migrationError->getMessage()));
    }
}
