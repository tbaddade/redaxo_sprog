<?php

/**
 * This file is part of the Wildcard package.
 *
 * @author (c) Thomas Blum <thomas@addoff.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
use \Wildcard\Wildcard;

if (!rex::isBackend()) {
    \rex_extension::register('OUTPUT_FILTER', '\Wildcard\Extension::replace');
}

if (rex::isBackend() && rex::getUser()) {
    \rex_extension::register('CLANG_ADDED', '\Wildcard\Extension::clangAdded');
    \rex_extension::register('CLANG_DELETED', '\Wildcard\Extension::clangDeleted');


    rex_extension::register('PAGES_PREPARED', function () {
        if (\rex_be_controller::getCurrentPage() == 'wildcard/settings') {
            $func = rex_request('func', 'string');
            if ($func == 'update') {
                \rex_config::set('wildcard', 'clang_switch', rex_request('clang_switch', 'int'));
            }
        }
        $page = \rex_be_controller::getPageObject('wildcard/wildcard');
        if (Wildcard::isClangSwitchMode()) {
            $clang_id = str_replace('clang', '', rex_be_controller::getCurrentPagePart(3));
            $page->setSubPath(rex_path::addon('wildcard', 'pages/wildcard.clang_switch.php'));
            foreach (\rex_clang::getAll() as $id => $clang) {
                $page->addSubpage((new rex_be_page('clang' . $id, $clang->getName()))
                    ->setSubPath(rex_path::addon('wildcard', 'pages/wildcard.clang_switch.php'))
                    ->setIsActive($id == $clang_id)
                );
            }
        } else {
            $page->setSubPath(rex_path::addon('wildcard', 'pages/wildcard.clang_all.php'));
        }
    });

}
