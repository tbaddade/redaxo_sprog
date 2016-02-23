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

class_alias('\Sprog\Wildcard', 'Wildcard');

rex_perm::register('sprog[wildcard]', null, rex_perm::OPTIONS);

/**
 * Replaced some wildcards in given text
 */
function sprogdown($text, $clang_id = null) {
	return Wildcard::parse($text, $clang_id);
}
/**
 * Replaced given wildcard
 */
function sprogcard($wildcard, $clang_id = null) {
	return Wildcard::replace($wildcard, $clang_id);
}
/**
 * Returns a field with the suffix of the current clang id
 */
function sprogfield($field, $separator = '_') {
	return $field . $separator . rex_clang::getCurrentId();
}
/**
 * Returns the value by given an array and field.
 * The field will be modified with the suffix of the current clang id
 */
function sprogvalue(array $array, $field, $fallback_clang_id = 0, $separator = '_') {
    $modifiedField = sprogfield($field, $separator);
    if (isset($array[$modifiedField])) {
        return $array[$modifiedField];
    }

    $modifiedField = $field . $separator . $fallback_clang_id;
    if (isset($array[$modifiedField])) {
        return $array[$modifiedField];
    }

    if (isset($array[$field])) {
        return $array[$field];
    }

    return false;
}
/**
 * Returns a modified array
 *
 * $array = [
 *     'headline_1' => 'DE Überschrift',
 *     'headline_2' => 'EN Heading',
 *     'text_1' => 'DE Zwei flinke Boxer jagen die quirlige Eva und ihren Mops durch Sylt.',
 *     'text_2' => 'EN The quick, brown fox jumps over a lazy dog.',
 * ];
 * $fields = ['headline', 'text'];
 *
 * E.g. The current clang_id is 1 for german
 * $array = sprogarray($array, $fields);
 * Array
 * (
 *     'headline_1' => 'DE Überschrift',
 *     'headline_2' => 'EN Heading',
 *     'text_1' => 'DE Zwei flinke Boxer jagen die quirlige Eva und ihren Mops durch Sylt.',
 *     'text_2' => 'EN The quick, brown fox jumps over a lazy dog.',
 *     'headline' => 'DE Überschrift',
 *     'text' => 'DE Zwei flinke Boxer jagen die quirlige Eva und ihren Mops durch Sylt.',
 * )
 */
function sprogarray(array $array, array $fields, $fallback_clang_id = 0, $separator = '_') {
    foreach ($fields as $field) {
        $array[$field] = sprogvalue($array, $field, $fallback_clang_id, $separator);
    }
    return $array;
}


if (!rex::isBackend()) {
    \rex_extension::register('OUTPUT_FILTER', '\Sprog\Extension::replaceWildcards');
}

if (rex::isBackend() && rex::getUser()) {
    if ($this->getConfig('sync_structure_category_name_to_article_name')) {
        // Bug #607
        // https://github.com/redaxo/redaxo/issues/607
        // CAT_UPDATED wird nicht ausgelöst, wenn `pjax = true`
        $structureAddon = rex_addon::get('structure');
        $structurePropertyPage = $structureAddon->getProperty('page');
        $structurePropertyPage['pjax'] = false;
        $structureAddon->setProperty('page', $structurePropertyPage );
    }

    /*
    |--------------------------------------------------------------------------
    | ART_STATUS / ART_UPDATED / ART_META_UPDATED
    |--------------------------------------------------------------------------
    | LATE, damit MetaInfo die neuen Daten zunächst in die DB schreiben kann
    */
    \rex_extension::register('ART_STATUS', '\Sprog\Extension::articleUpdated');
    \rex_extension::register('ART_UPDATED', '\Sprog\Extension::articleUpdated');
    \rex_extension::register('ART_META_UPDATED', '\Sprog\Extension::articleMetadataUpdated', rex_extension::LATE);

    /*
    |--------------------------------------------------------------------------
    | CAT_STATUS / CAT_UPDATED
    |--------------------------------------------------------------------------
    | LATE, damit MetaInfo die neuen Daten zunächst in die DB schreiben kann
    */
    \rex_extension::register('CAT_STATUS', '\Sprog\Extension::categoryUpdated');
    \rex_extension::register('CAT_UPDATED', '\Sprog\Extension::categoryUpdated', rex_extension::LATE);

    /*
    | Medienpool ist noch nicht mehrsprachig
    |
    |--------------------------------------------------------------------------
    | MEDIA_ADDED / MEDIA_UPDATED
    |--------------------------------------------------------------------------
    | LATE, damit MetaInfo die neuen Daten zunächst in die DB schreiben kann
    */
    // rex_extension::register('MEDIA_ADDED', '\Sprog\Extension::mediaUpdated', rex_extension::LATE);
    // rex_extension::register('MEDIA_UPDATED', '\Sprog\Extension::mediaUpdated', rex_extension::LATE);

    /*
    |--------------------------------------------------------------------------
    | CLANG_ADDED / CLANG_DELETED
    |--------------------------------------------------------------------------
    */
    \rex_extension::register('CLANG_ADDED', '\Sprog\Extension::clangAdded');
    \rex_extension::register('CLANG_DELETED', '\Sprog\Extension::clangDeleted');

    /*
    |--------------------------------------------------------------------------
    | PAGES_PREPARED
    |--------------------------------------------------------------------------
    */
    rex_extension::register('PAGES_PREPARED', function () {
        if (rex::getUser()->isAdmin()) {
            if (\rex_be_controller::getCurrentPage() == 'sprog/settings') {
                $func = rex_request('func', 'string');
                if ($func == 'update') {
                    \rex_config::set('sprog', 'wildcard_clang_switch', rex_request('clang_switch', 'bool'));
                }
            }
        }

        if (rex::getUser()->isAdmin() || rex::getUser()->hasPerm('sprog[wildcard]')) {
            $page = \rex_be_controller::getPageObject('sprog/wildcard');

            if (Wildcard::isClangSwitchMode()) {
                $clang_id = str_replace('clang', '', rex_be_controller::getCurrentPagePart(3));
                $page->setSubPath(rex_path::addon('sprog', 'pages/wildcard.clang_switch.php'));
                foreach (\rex_clang::getAll() as $id => $clang) {
                    if (rex::getUser()->getComplexPerm('clang')->hasPerm($id)) {
                        $page->addSubpage((new rex_be_page('clang' . $id, $clang->getName()))
                            ->setSubPath(rex_path::addon('sprog', 'pages/wildcard.clang_switch.php'))
                            ->setIsActive($id == $clang_id)
                        );
                    }
                }
            } else {
                $page->setSubPath(rex_path::addon('sprog', 'pages/wildcard.clang_all.php'));
            }
        }
    });
}
