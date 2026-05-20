<?php

/**
 * This file is part of the Sprog package.
 *
 * @author (c) Thomas Blum <thomas@addoff.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Sprog\Copy\StructureContent;

if (!rex_csrf_token::factory('sprog-copy-content')->isValid()) {
    echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
    return;
}

// generate page cache
$articles = rex_get('articles', 'string');
$params = rex_get('params', 'array');

if (!empty($articles)) {
    StructureContent::fire(StructureContent::resolveItems($articles), $params);
}

// clear output
StructureContent::clearOutput();
