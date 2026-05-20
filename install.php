<?php

declare(strict_types=1);
use Sprog\Schema\V1Schema;
use Sprog\Schema\V2Schema;

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
