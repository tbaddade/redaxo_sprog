<?php

/**
 * This file is part of the Sprog package.
 *
 * @author (c) Thomas Blum <thomas@addoff.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use \Sprog\Wildcard;

rex_perm::register('sprog[wildcard]', null, rex_perm::OPTIONS);

if (!rex::isBackend()) {
    \rex_extension::register('OUTPUT_FILTER', '\Sprog\Extension::replaceWildcards');
}

if (rex::isBackend() && rex::getUser()) {
    \rex_extension::register('CLANG_ADDED', '\Sprog\Extension::clangAdded');
    \rex_extension::register('CLANG_DELETED', '\Sprog\Extension::clangDeleted');


    rex_extension::register('PAGES_PREPARED', function () {
        if (rex::getUser()->isAdmin()) {
            if (\rex_be_controller::getCurrentPage() == 'sprog/settings') {
                $func = rex_request('func', 'string');
                if ($func == 'update') {
                    \rex_config::set('sprog', 'wildcard_clang_switch', rex_request('clang_switch', 'int'));
                }
            }
        }

        if (rex::getUser()->isAdmin() || rex::getUser()->hasPerm('sprog[wildcard]')) {
            $page = \rex_be_controller::getPageObject('sprog/wildcard');

            if (Wildcard::isClangSwitchMode()) {
                $clang_id = str_replace('clang', '', rex_be_controller::getCurrentPagePart(3));
                $page->setSubPath(rex_path::addon('sprog', 'pages/wildcard.clang_switch.php'));
                foreach (\rex_clang::getAll() as $id => $clang) {
                    $page->addSubpage((new rex_be_page('clang' . $id, $clang->getName()))
                        ->setSubPath(rex_path::addon('sprog', 'pages/wildcard.clang_switch.php'))
                        ->setIsActive($id == $clang_id)
                    );
                }
            } else {
                $page->setSubPath(rex_path::addon('sprog', 'pages/wildcard.clang_all.php'));
            }
        }
    });
}
