<?php

/**
 * This file is part of the Sprog package.
 *
 * @author (c) Thomas Blum <thomas@addoff.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Sprog\Copy\StructureMetadata;

if (!rex_csrf_token::factory('sprog_copy_metadata')->isValid()) {
    echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
    return;
}

// generate page cache
$articles = rex_get('articles', 'string');
$params = rex_get('params', 'array');

if (!empty($articles)) {
    StructureMetadata::fire(StructureMetadata::resolveItems($articles), $params);
}

// clear output
StructureMetadata::clearOutput();
