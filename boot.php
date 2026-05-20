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

$addon = rex_addon::get('sprog');

/**
 * @deprecated since version 1.3.0, use \Sprog\Wildcard
 */
class_alias('\Sprog\Wildcard', 'Wildcard');

rex_perm::register('sprog[abbreviation]', null, rex_perm::OPTIONS);
rex_perm::register('sprog[wildcard]', null, rex_perm::OPTIONS);
rex_perm::register('sprog[unit_edit]', null, rex_perm::OPTIONS);

require_once __DIR__ . '/functions/sprog.php';

FilterRegistry::publish($addon->getProperty('filter'));

if (!rex::isBackend()) {
    rex_extension::register('OUTPUT_FILTER', [Extension::class, 'replaceWildcards'], rex_extension::NORMAL);
    rex_extension::register('OUTPUT_FILTER', [Extension::class, 'replaceAbbreviations'], rex_extension::NORMAL);
    rex_extension::register('OUTPUT_FILTER', [Extension::class, 'replaceForeignwords'], rex_extension::NORMAL);
}

if (rex::isBackend() && rex::getUser()) {
    // ART_*/CAT_*-Hooks: LATE, damit MetaInfo seine Daten zuerst persistiert.
    rex_extension::register('ART_STATUS', [Extension::class, 'articleUpdated']);
    rex_extension::register('ART_UPDATED', [Extension::class, 'articleUpdated']);
    rex_extension::register('ART_META_UPDATED', [Extension::class, 'articleMetadataUpdated'], rex_extension::LATE);
    rex_extension::register('CAT_STATUS', [Extension::class, 'categoryUpdated']);
    rex_extension::register('CAT_UPDATED', [Extension::class, 'categoryUpdated'], rex_extension::LATE);

    // Medienpool ist noch nicht mehrsprachig — MEDIA_* daher bewusst aus.
    // rex_extension::register('MEDIA_ADDED', [Extension::class, 'mediaUpdated'], rex_extension::LATE);
    // rex_extension::register('MEDIA_UPDATED', [Extension::class, 'mediaUpdated'], rex_extension::LATE);

    rex_extension::register('CLANG_ADDED', [Extension::class, 'clangAdded']);
    rex_extension::register('CLANG_DELETED', [Extension::class, 'clangDeleted']);

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
