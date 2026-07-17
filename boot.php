<?php

/**
 * This file is part of the Sprog package.
 *
 * @author (c) Thomas Blum <thomas@addoff.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Sprog\Boot\AssetRegistry;
use Sprog\Boot\FilterRegistry;
use Sprog\Boot\PageTreeBuilder;
use Sprog\Extension;
use Sprog\Support\StructureClangGuard;
use Sprog\View\LangCompare;

$addon = rex_addon::get('sprog');

/**
 * @deprecated since version 1.3.0, use \Sprog\Wildcard
 */
class_alias('\Sprog\Wildcard', 'Wildcard');

rex_perm::register('sprog[unit_edit]', null, rex_perm::OPTIONS);
// Workflow-Rollen (je Sprache über die clang-Zuweisung wirksam): translator
// reicht Übersetzungen zur Prüfung ein, reviewer gibt frei / gibt zurück.
rex_perm::register('sprog[translator]', null, rex_perm::OPTIONS);
rex_perm::register('sprog[reviewer]', null, rex_perm::OPTIONS);

require_once __DIR__ . '/functions/sprog.php';

FilterRegistry::publish($addon->getProperty('filter'));

if (!rex::isBackend()) {
    rex_extension::register('OUTPUT_FILTER', [Extension::class, 'replaceWildcards'], rex_extension::NORMAL);
    rex_extension::register('OUTPUT_FILTER', [Extension::class, 'replaceAbbreviations'], rex_extension::NORMAL);
    rex_extension::register('OUTPUT_FILTER', [Extension::class, 'replaceForeignwords'], rex_extension::NORMAL);
}

if (rex::isBackend() && rex::getUser()) {
    // Status separat (nur echter Statuswechsel gleicht den Status an).
    rex_extension::register('ART_STATUS', [Extension::class, 'statusUpdated']);
    rex_extension::register('CAT_STATUS', [Extension::class, 'statusUpdated']);
    // Name/Template/MetaInfo bei Update. META_UPDATED/CAT_UPDATED LATE, damit
    // der MetaInfo-Handler seine Daten zuerst persistiert.
    rex_extension::register('ART_UPDATED', [Extension::class, 'articleUpdated']);
    rex_extension::register('ART_META_UPDATED', [Extension::class, 'articleMetadataUpdated'], rex_extension::LATE);
    rex_extension::register('CAT_UPDATED', [Extension::class, 'categoryUpdated'], rex_extension::LATE);

    // Medienpool ist noch nicht mehrsprachig — MEDIA_* daher bewusst aus.
    // rex_extension::register('MEDIA_ADDED', [Extension::class, 'mediaUpdated'], rex_extension::LATE);
    // rex_extension::register('MEDIA_UPDATED', [Extension::class, 'mediaUpdated'], rex_extension::LATE);

    rex_extension::register('CLANG_ADDED', [Extension::class, 'clangAdded']);
    rex_extension::register('CLANG_DELETED', [Extension::class, 'clangDeleted']);

    // Artikel-Sprachvergleich in die Struktur-Content-Maske einhängen (Toolbar
    // oben, Panel-Host unter der Slice-Liste). Rechte werden im Endpoint
    // (pages/sprog.langcompare.php) und beim Aufbau der Sprachauswahl geprüft.
    if (LangCompare::isEnabled()) {
        rex_extension::register('STRUCTURE_CONTENT_HEADER', [Extension::class, 'langCompareHeader']);
        rex_extension::register('STRUCTURE_CONTENT_AFTER_SLICES', [Extension::class, 'langCompareContainer']);
    }

    // Struktur-Sprachfilter (DEV-Opt-in, Config `structure_clang_filter`): blendet
    // in Kategoriebaum (PAGE_STRUCTURE_HEADER) und Content-Maske
    // (STRUCTURE_CONTENT_HEADER) die Sprach-Buttons aus, die die yrewrite-Domain
    // der Kategorie nicht bedient. Kein Editor-UI — s. StructureClangGuard.
    if (StructureClangGuard::isEnabled()) {
        rex_extension::register('PAGE_STRUCTURE_HEADER', [Extension::class, 'structureClangData']);
        rex_extension::register('STRUCTURE_CONTENT_HEADER', [Extension::class, 'structureClangData']);
    }

    rex_extension::register('PAGES_PREPARED', static function (): void {
        PageTreeBuilder::publish();
    });

    AssetRegistry::publish($addon);
}
