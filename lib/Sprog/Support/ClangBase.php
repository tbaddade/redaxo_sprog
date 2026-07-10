<?php

declare(strict_types=1);

namespace Sprog\Support;

use rex_clang;
use rex_config;

use function array_map;
use function array_values;
use function is_array;

/**
 * Sprachbasis (`clang_base`) — welche Sprachen sind eigenständig übersetzbar
 * und welche spiegeln nur eine andere?
 *
 * In der sprog-Config verweist `clang_base` jede Sprache auf ihre Basis. Zeigt
 * eine Sprache auf sich selbst (Default), ist sie eigenständig. Zeigt sie auf
 * eine ANDERE Sprache (z. B. nl_BE → nl_NL, en_AU → en_US), ist sie
 * „abgeleitet": ihre Ausgabe wird zur Laufzeit aus der Basis aufgelöst
 * (siehe WildcardLookupService::resolveClang), also braucht sie keine eigenen
 * Übersetzungen und taucht in der Übersetzungs-Oberfläche nicht auf.
 *
 * Single source of truth für diese Unterscheidung — ALLE Stellen, die
 * „übersetzbare Sprachen" meinen (Inbox, Dashboard, Glossar, Batch/MT), fragen
 * hier, statt clang_base selbst zu interpretieren. Gegenstück zu [[BaseLang]],
 * das die eine Quellsprache bestimmt.
 */
final class ClangBase
{
    /**
     * Rohe clang_base-Zuordnung `clangId => basisClangId`. Werte können als
     * String vorliegen (Formular-Input) — Aufrufer casten via isDerived().
     *
     * @return array<int, int|string>
     */
    public static function map(): array
    {
        $base = rex_config::get('sprog', 'clang_base');

        return is_array($base) ? $base : [];
    }

    /**
     * Ist die Sprache abgeleitet, d. h. verweist ihre Sprachbasis auf eine
     * ANDERE Sprache? Der (int)-Cast normalisiert String-Werte aus dem
     * Formular, damit eine Selbst-Zuordnung ("1" für Sprache 1) korrekt als
     * „nicht abgeleitet" erkannt wird.
     */
    public static function isDerived(int $clangId): bool
    {
        $map = self::map();

        return isset($map[$clangId]) && (int) $map[$clangId] !== $clangId;
    }

    /**
     * Alle eigenständig übersetzbaren Sprachen (rex_clang::getAll() ohne die
     * abgeleiteten), Schlüssel bleiben die clang-IDs.
     *
     * @return array<int, rex_clang>
     */
    public static function translatableClangs(): array
    {
        $clangs = rex_clang::getAll();
        foreach ($clangs as $id => $_clang) {
            if (self::isDerived($id)) {
                unset($clangs[$id]);
            }
        }

        return $clangs;
    }

    /**
     * @return list<int>
     */
    public static function translatableIds(): array
    {
        return array_values(array_map(
            static fn (rex_clang $clang): int => $clang->getId(),
            self::translatableClangs(),
        ));
    }
}
