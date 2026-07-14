<?php

declare(strict_types=1);

namespace Sprog\Schema;

use rex;
use rex_sql;
use rex_sql_column;
use rex_sql_exception;
use rex_sql_index;
use rex_sql_table;

/**
 * Schema-Definitionen für Sprog v2.
 *
 * Die v2-Tabellen werden parallel zu v1 angelegt; v1 bleibt während der
 * gesamten v2.x-Reihe unverändert. Die Migration der Bestandsdaten erfolgt
 * in einem eigenen Schritt über den Sprog\Service\MigrationService und die
 * Sprog\Migration\*Migrator (v1 → v2); bei Installation/Update wird sie von
 * install.php automatisch angestoßen.
 *
 * ensure() ist idempotent und kann beliebig oft aufgerufen werden — die
 * darunterliegende REDAXO-API legt nur an, was noch fehlt.
 */
final class V2Schema
{
    // Die Spalten sprog_translation.status und sprog_unit.source_type sind
    // bewusst kein MySQL-ENUM (additive Erweiterung in v2.x soll keine teuren
    // ALTERs auslösen). Whitelist und Single-Source-of-Truth sind die Enums
    // Sprog\Enum\Status und Sprog\Enum\SourceType — Validierung läuft
    // PHP-seitig im Service- und Repository-Layer via ::from() / ::tryFrom().

    public static function ensure(): void
    {
        self::ensureUnitTable();
        self::ensureTranslationTable();
        self::ensureGlossaryTable();
        self::ensureTranslationMemoryTable();
        self::ensureActivityTable();
        self::ensureTranslationHistoryTable();
    }

    /**
     * Gegenstück zu ensure() — droppt die v2-Tabellen. Wird beim Uninstall
     * des Addons aus uninstall.php aufgerufen. Reihenfolge: translation und
     * activity referenzieren sprog_unit per unit_id, also zuerst die
     * abhängigen Tabellen droppen. rex_sql_table::drop() ist idempotent
     * (DROP TABLE IF EXISTS).
     */
    public static function drop(): void
    {
        rex_sql_table::get(rex::getTable('sprog_translation_history'))->drop();
        rex_sql_table::get(rex::getTable('sprog_activity'))->drop();
        rex_sql_table::get(rex::getTable('sprog_tm'))->drop();
        rex_sql_table::get(rex::getTable('sprog_glossary'))->drop();
        rex_sql_table::get(rex::getTable('sprog_translation'))->drop();
        rex_sql_table::get(rex::getTable('sprog_unit'))->drop();
    }

    /**
     * Eine Übersetzungseinheit — sprachunabhängiger Anker.
     * Pro Wildcard, Artikel-Name, Slice-Feld o.ä. genau eine Row.
     */
    private static function ensureUnitTable(): void
    {
        // TODO(v3): Diesen ALTER-Schnipsel raus oder in eine versionierte
        // Schema-Migration umziehen — er ist ein einmaliger v2.0-Frühzeit-Fix,
        // läuft aber bei jedem ensure() versuchsweise mit. Alten UNIQUE-Index
        // droppen, falls vorhanden: wurde durch unit_namespace_context_key
        // abgelöst, sonst würde er die Mehrfach-Verwendung gleicher unit_keys
        // in unterschiedlichen `context`-Werten blockieren. Idempotent:
        // existiert der Index nicht (mehr), schluckt der Try den Fehler.
        try {
            rex_sql::factory()->setQuery(
                'ALTER TABLE ' . rex::getTable('sprog_unit') . ' DROP INDEX unit_namespace_key',
            );
        } catch (rex_sql_exception) {
            // Index existierte nicht — fine, weiter.
        }

        rex_sql_table::get(rex::getTable('sprog_unit'))
            ->ensureColumn(new rex_sql_column('id', 'int(11) unsigned', false, null, 'auto_increment'))
            ->setPrimaryKey('id')

            // Kategorie der Einheit / Replacement-Provider, z.B. 'wildcard',
            // 'article.name', 'slice.text'. Bestimmt, WIE der Wert beim Output
            // ersetzt wird. Whitelist: Sprog\Enum\SourceType.
            ->ensureColumn(new rex_sql_column('namespace', 'varchar(64)'))

            // User-definierter Kontext / Bereich, z.B. "page.about", "form.contact".
            // Zweite Hierarchie-Ebene über dem unit_key, damit ein Redakteur
            // mehrere `title`-Keys für unterschiedliche Bereiche der Seite
            // anlegen kann, ohne dass die Keys kollidieren. NOT NULL DEFAULT ''
            // ist gewollt: MySQL-UNIQUE behandelt NULL-Werte als nicht
            // eindeutig — mit leerem String greift der UNIQUE-Index auch für
            // "kein Bereich gesetzt".
            ->ensureColumn(new rex_sql_column('context', 'varchar(64)', false, ''))

            // Identifier innerhalb des Namespace+Context.
            // Bei Wildcards: der Tag-Name. Bei strukturierten Inhalten: i.d.R.
            // die REDAXO-ID (Artikel/Slice/Media) — als string, weil source_ref
            // potentiell auch nicht-numerische Keys aufnehmen kann.
            // 191 wegen utf8mb4-UNIQUE-Index-Grenze unter InnoDB.
            ->ensureColumn(new rex_sql_column('unit_key', 'varchar(191)'))

            // Optionale Backlinks auf REDAXO-Entitäten. source_type doppelt sich
            // semantisch mit namespace, ist aber bewusst eigene Spalte für
            // schnelles "alle Units zu Artikel 42" ohne Namespace-Parsing.
            ->ensureColumn(new rex_sql_column('source_type', 'varchar(32)', true))
            ->ensureColumn(new rex_sql_column('source_ref', 'varchar(191)', true))

            // SHA-256 des Quell-Sprach-Wertes; Vergleich mit
            // sprog_translation.source_hash_at_translation erkennt "stale".
            ->ensureColumn(new rex_sql_column('source_hash', 'char(64)', true))

            // Freie Tags (JSON-Array) und Übersetzer-Notizen.
            // JSON wird per JSON_THROW_ON_ERROR encoded/decoded; kein MySQL-JSON,
            // weil ältere MariaDB-Versionen das uneinheitlich handhaben.
            ->ensureColumn(new rex_sql_column('tags', 'text', true))
            ->ensureColumn(new rex_sql_column('notes', 'text', true))

            ->ensureGlobalColumns()

            // UNIQUE über (namespace, context, unit_key): zwei Units mit
            // demselben unit_key dürfen koexistieren, solange sich namespace
            // ODER context unterscheidet. Damit kann z.B. `(wildcard, page.about, title)`
            // neben `(wildcard, page.home, title)` stehen.
            ->ensureIndex(new rex_sql_index('unit_namespace_context_key', ['namespace', 'context', 'unit_key'], rex_sql_index::UNIQUE))
            ->ensureIndex(new rex_sql_index('unit_source', ['source_type', 'source_ref']))
            ->ensure();
    }

    /**
     * Eine konkrete Übersetzung: ein (unit, clang)-Paar mit Wert und Workflow-Status.
     */
    private static function ensureTranslationTable(): void
    {
        rex_sql_table::get(rex::getTable('sprog_translation'))
            ->ensureColumn(new rex_sql_column('id', 'int(11) unsigned', false, null, 'auto_increment'))
            ->setPrimaryKey('id')

            ->ensureColumn(new rex_sql_column('unit_id', 'int(11) unsigned'))
            ->ensureColumn(new rex_sql_column('clang_id', 'int(11) unsigned'))

            // mediumtext (≤ 16 MB) deckt selbst sehr lange Marketingtexte ab.
            ->ensureColumn(new rex_sql_column('value', 'mediumtext'))

            // SHA-256 von value; beschleunigt Vergleiche und TM-Dedupe.
            ->ensureColumn(new rex_sql_column('value_hash', 'char(64)', true))

            // SHA-256 des Quell-Sprach-Wertes zum Zeitpunkt der letzten
            // Übersetzung. Differenz zu sprog_unit.source_hash => "stale".
            ->ensureColumn(new rex_sql_column('source_hash_at_translation', 'char(64)', true))

            // Workflow-Status; Whitelist: Sprog\Enum\Status.
            ->ensureColumn(new rex_sql_column('status', 'varchar(32)', false, 'missing'))

            // MT-Metadaten: welcher Provider hat den Draft erzeugt und mit welcher
            // gemeldeten Confidence (0.00 - 1.00). NULL = keine MT involviert.
            ->ensureColumn(new rex_sql_column('mt_provider', 'varchar(32)', true))
            ->ensureColumn(new rex_sql_column('mt_confidence', 'decimal(3,2)', true))

            // Autor und Reviewer als rex_user.id; bewusst NULL-bar (System-Imports
            // u.ä. haben keinen menschlichen Autor).
            ->ensureColumn(new rex_sql_column('translator_id', 'int(11) unsigned', true))
            ->ensureColumn(new rex_sql_column('reviewer_id', 'int(11) unsigned', true))

            // Optimistic-Locking-Zähler; wird bei jedem Update inkrementiert.
            ->ensureColumn(new rex_sql_column('revision', 'int(11) unsigned', false, '0'))

            ->ensureGlobalColumns()

            ->ensureIndex(new rex_sql_index('translation_unit_clang', ['unit_id', 'clang_id'], rex_sql_index::UNIQUE))
            ->ensureIndex(new rex_sql_index('translation_clang_status', ['clang_id', 'status']))
            ->ensure();
    }

    /**
     * Glossar: feste Übersetzungen für Fachbegriffe, pro Sprachpaar.
     * Werden im MT-Prompt als bindende Vorgabe mitgegeben.
     */
    private static function ensureGlossaryTable(): void
    {
        rex_sql_table::get(rex::getTable('sprog_glossary'))
            ->ensureColumn(new rex_sql_column('id', 'int(11) unsigned', false, null, 'auto_increment'))
            ->setPrimaryKey('id')

            ->ensureColumn(new rex_sql_column('source_clang_id', 'int(11) unsigned'))
            ->ensureColumn(new rex_sql_column('target_clang_id', 'int(11) unsigned'))

            ->ensureColumn(new rex_sql_column('source_term', 'varchar(191)'))
            ->ensureColumn(new rex_sql_column('target_term', 'varchar(191)'))

            // notes auf 500 Zeichen begrenzt, deckungsgleich mit
            // GlossaryService::MAX_NOTES_LENGTH (PHP-seitige Validierung).
            ->ensureColumn(new rex_sql_column('notes', 'varchar(500)', true))

            ->ensureGlobalColumns()

            ->ensureIndex(new rex_sql_index('glossary_lookup', ['source_clang_id', 'target_clang_id', 'source_term'], rex_sql_index::UNIQUE))
            ->ensure();
    }

    /**
     * Translation Memory: schon einmal übersetzte Satzpaare,
     * dienen als Fuzzy-Match-Vorschläge im Editor.
     *
     * Im Gegensatz zum Glossar nicht bindend, sondern empfehlend.
     */
    private static function ensureTranslationMemoryTable(): void
    {
        rex_sql_table::get(rex::getTable('sprog_tm'))
            ->ensureColumn(new rex_sql_column('id', 'int(11) unsigned', false, null, 'auto_increment'))
            ->setPrimaryKey('id')

            ->ensureColumn(new rex_sql_column('source_clang_id', 'int(11) unsigned'))
            ->ensureColumn(new rex_sql_column('target_clang_id', 'int(11) unsigned'))

            // Exact-Match-Index über den Hash. Fuzzy-Match passiert PHP-seitig
            // (Levenshtein/Jaccard auf den source_segment-Kandidaten zu einer Sprache).
            ->ensureColumn(new rex_sql_column('source_hash', 'char(64)'))

            ->ensureColumn(new rex_sql_column('source_segment', 'mediumtext'))
            ->ensureColumn(new rex_sql_column('target_segment', 'mediumtext'))

            ->ensureGlobalColumns()

            ->ensureIndex(new rex_sql_index('tm_lookup', ['source_clang_id', 'target_clang_id', 'source_hash']))
            ->ensure();
    }

    /**
     * Audit-Log: jede Statusänderung, jeder MT-Lauf, jede Edit-Aktion
     * landet hier als unveränderlicher Eintrag.
     *
     * Bewusst ohne ensureGlobalColumns() — der Log ist append-only,
     * Updates auf bestehende Activity-Rows darf es nicht geben.
     * bigint(20) für die id, weil Activity-Tabellen schnell wachsen.
     */
    private static function ensureActivityTable(): void
    {
        rex_sql_table::get(rex::getTable('sprog_activity'))
            ->ensureColumn(new rex_sql_column('id', 'bigint(20) unsigned', false, null, 'auto_increment'))
            ->setPrimaryKey('id')

            // Nullable, weil manche Aktionen (z.B. globaler Import) sich nicht
            // auf eine konkrete Unit/Translation beziehen.
            ->ensureColumn(new rex_sql_column('unit_id', 'int(11) unsigned', true))
            ->ensureColumn(new rex_sql_column('translation_id', 'int(11) unsigned', true))

            // rex_user.id; NULL bei System-Aktionen (Cronjob, Auto-Import).
            ->ensureColumn(new rex_sql_column('user_id', 'int(11) unsigned', true))

            // Maschinenlesbarer Aktionstyp, z.B. 'translation.updated',
            // 'translation.approved', 'mt.draft_created'. Whitelist im
            // ActivityService.
            ->ensureColumn(new rex_sql_column('action', 'varchar(32)'))

            // JSON-Payload mit Aktions-Details (z.B. {"old":"x","new":"y"});
            // JSON_THROW_ON_ERROR im Service.
            ->ensureColumn(new rex_sql_column('payload', 'text', true))

            ->ensureColumn(new rex_sql_column('created_at', 'datetime'))

            ->ensureIndex(new rex_sql_index('activity_unit', ['unit_id', 'created_at']))
            ->ensureIndex(new rex_sql_index('activity_translation', ['translation_id', 'created_at']))
            // Standalone-Index auf created_at: ActivityRepository::deleteOlderThan()
            // läuft als DELETE … WHERE created_at < :threshold. Sobald ein Retention-
            // Cronjob aktiv wird, hält der Index die DELETE-Laufzeit kurz und
            // verhindert eine längere Sperre der Audit-Tabelle.
            ->ensureIndex(new rex_sql_index('activity_created_at', ['created_at']))
            ->ensure();
    }

    /**
     * Versions-Historie: pro gespeicherter Wert-Änderung einer Übersetzung ein
     * Snapshot. Basis für mehrstufiges Undo („Verlauf"/„Wiederherstellen") im
     * Inbox-Akkordeon.
     *
     * Abgrenzung zu sprog_activity: das Activity-Log ist ein schlankes
     * Audit-Log (nur Hashes, keine Klartext-Werte). Die Historie speichert die
     * VOLLEN Werte, damit ein früherer Stand tatsächlich zurückgeholt werden
     * kann. Wie Activity append-only (kein ensureGlobalColumns), bigint(20)-id.
     */
    private static function ensureTranslationHistoryTable(): void
    {
        rex_sql_table::get(rex::getTable('sprog_translation_history'))
            ->ensureColumn(new rex_sql_column('id', 'bigint(20) unsigned', false, null, 'auto_increment'))
            ->setPrimaryKey('id')

            // FK auf sprog_translation.id (wie sprog_activity.translation_id).
            // unit_id/clang_id sind Komfort-Backrefs für Anzeige und Prune, ohne
            // dass die Translation nachgeladen werden muss.
            ->ensureColumn(new rex_sql_column('translation_id', 'int(11) unsigned'))
            ->ensureColumn(new rex_sql_column('unit_id', 'int(11) unsigned'))
            ->ensureColumn(new rex_sql_column('clang_id', 'int(11) unsigned'))

            // Voller Snapshot des Wertes zu dieser Version (mediumtext wie
            // sprog_translation.value) + Hash für schnelle Vergleiche.
            ->ensureColumn(new rex_sql_column('value', 'mediumtext'))
            ->ensureColumn(new rex_sql_column('value_hash', 'char(64)', true))

            // Status zum Snapshot-Zeitpunkt (Whitelist Sprog\Enum\Status).
            ->ensureColumn(new rex_sql_column('status', 'varchar(32)'))

            // MT-Herkunft dieser Version, falls maschinell erzeugt.
            ->ensureColumn(new rex_sql_column('mt_provider', 'varchar(32)', true))
            ->ensureColumn(new rex_sql_column('mt_confidence', 'decimal(3,2)', true))

            // Wie diese Version entstand: 'manual' | 'mt' | 'restore'
            // (erweiterbar: import/sync/copy). Für die Anzeige im Verlauf.
            ->ensureColumn(new rex_sql_column('origin', 'varchar(32)'))

            // rex_user.id des Verursachers; NULL bei System-Aktionen.
            ->ensureColumn(new rex_sql_column('user_id', 'int(11) unsigned', true))

            ->ensureColumn(new rex_sql_column('created_at', 'datetime'))

            ->ensureIndex(new rex_sql_index('history_translation', ['translation_id', 'created_at']))
            ->ensureIndex(new rex_sql_index('history_created_at', ['created_at']))
            ->ensure();
    }
}
