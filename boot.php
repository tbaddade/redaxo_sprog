<?php

/**
 * This file is part of the Wildcard package.
 *
 * @author (c) Thomas Blum <thomas@addoff.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

if (! rex::isBackend()) {

    rex_extension::register('OUTPUT_FILTER', '\Wildcard\Extension::replace');

}


if (rex::isBackend() && rex::getUser()) {

    rex_extension::register('CLANG_ADDED', '\Wildcard\Extension::clangAdded');
    rex_extension::register('CLANG_DELETED', '\Wildcard\Extension::clangDeleted');

}
