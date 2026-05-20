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

$addon = rex_addon::get('sprog');

/**
 * @deprecated since version 1.3.0, use \Sprog\Wildcard
 */
class_alias('\Sprog\Wildcard', 'Wildcard');

rex_perm::register('sprog[abbreviation]', null, rex_perm::OPTIONS);
rex_perm::register('sprog[wildcard]', null, rex_perm::OPTIONS);
rex_perm::register('sprog[unit_edit]', null, rex_perm::OPTIONS);

// Wie viele Artikel ein einzelner Copy-Generate-Request abarbeitet.
// Höher = weniger Requests, längere Script-Laufzeit pro Request.
// (Debug-Modus in sprog.js misst die Laufzeiten.)
// TODO(v2-review NIT, boot.php:28): setConfig läuft pro Request und überschreibt
// jede User-Anpassung in rex_config. Entweder nach `default_config:` in
// package.yml verschieben (einmaliges Seeding beim Install) oder am Use-Site
// per `$addon->getConfig('chunkSizeArticles') ?? 4` lesen.
$addon->setConfig('chunkSizeArticles', 4);

require_once __DIR__ . '/functions/sprog.php';

FilterRegistry::publish($addon->getProperty('filter'));

// TODO(v2-review NIT, boot.php:34ff): EP-Callbacks unten sind als String-FQNs
// ('\Sprog\Extension::replaceWildcards' …) registriert. Konsequent zur
// v2-Modernisierung wäre `[\Sprog\Extension::class, 'replaceWildcards']`
// (oder `use Sprog\Extension;` + `[Extension::class, 'replaceWildcards']`).
// Vorteile: IDE-Navigation, Refactor-Rename greift, PHPStan löst den
// Callback auf. Funktional gleichwertig, daher nur NIT.
if (!rex::isBackend()) {
    rex_extension::register('OUTPUT_FILTER', '\Sprog\Extension::replaceWildcards', rex_extension::NORMAL);
    rex_extension::register('OUTPUT_FILTER', '\Sprog\Extension::replaceAbbreviations', rex_extension::NORMAL);
    rex_extension::register('OUTPUT_FILTER', '\Sprog\Extension::replaceForeignwords', rex_extension::NORMAL);
}

if (rex::isBackend() && rex::getUser()) {
    // ART_*/CAT_*-Hooks: LATE, damit MetaInfo seine Daten zuerst persistiert.
    rex_extension::register('ART_STATUS', '\Sprog\Extension::articleUpdated');
    rex_extension::register('ART_UPDATED', '\Sprog\Extension::articleUpdated');
    rex_extension::register('ART_META_UPDATED', '\Sprog\Extension::articleMetadataUpdated', rex_extension::LATE);
    rex_extension::register('CAT_STATUS', '\Sprog\Extension::categoryUpdated');
    rex_extension::register('CAT_UPDATED', '\Sprog\Extension::categoryUpdated', rex_extension::LATE);

    // Medienpool ist noch nicht mehrsprachig — MEDIA_* daher bewusst aus.
    // rex_extension::register('MEDIA_ADDED', '\Sprog\Extension::mediaUpdated', rex_extension::LATE);
    // rex_extension::register('MEDIA_UPDATED', '\Sprog\Extension::mediaUpdated', rex_extension::LATE);

    rex_extension::register('CLANG_ADDED', '\Sprog\Extension::clangAdded');
    rex_extension::register('CLANG_DELETED', '\Sprog\Extension::clangDeleted');

    rex_extension::register('PAGES_PREPARED', static function () use ($addon): void {
        PageTreeBuilder::publish($addon);
    });

    rex_extension::register('PAGE_BODY_ATTR', static function (rex_extension_point $ep): void {
        $subject = $ep->getSubject();
        $subject['class'][] = 'rex-page-sprog-copy-popup';
        $ep->setSubject($subject);
    });

    AssetRegistry::publish($addon);
}
