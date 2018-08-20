<?php

/**
 * This file is part of the Sprog package.
 *
 * @author (c) Thomas Blum <thomas@addoff.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Sprog\Wildcard;

class_alias('\Sprog\Wildcard', 'Wildcard');

rex_perm::register('sprog[wildcard]', null, rex_perm::OPTIONS);

// number of articles to generate per single request
// increase to speed up (reduces number of requests but extends script time)
// (hint: enable debug mode in sprog.js to report execution times)
$this->setConfig('chunkSizeArticles', 4);

/**
 * Replaced some wildcards in given text.
 */
function sprogdown($text, $clang_id = null)
{
    return Wildcard::parse($text, $clang_id);
}
/**
 * Replaced given wildcard.
 */
function sprogcard($wildcard, $clang_id = null)
{
    return Wildcard::get($wildcard, $clang_id);
}
/**
 * Returns a field with the suffix of the current clang id.
 */
function sprogfield($field, $separator = '_')
{
    return $field.$separator.rex_clang::getCurrentId();
}
/**
 * Returns the value by given an array and field.
 * The field will be modified with the suffix of the current clang id.
 */
function sprogvalue(array $array, $field, $fallback_clang_id = 0, $separator = '_')
{
    $modifiedField = sprogfield($field, $separator);
    if (isset($array[$modifiedField])) {
        return $array[$modifiedField];
    }

    $modifiedField = $field.$separator.$fallback_clang_id;
    if (isset($array[$modifiedField])) {
        return $array[$modifiedField];
    }

    if (isset($array[$field])) {
        return $array[$field];
    }

    return false;
}
/**
 * Returns a modified array.
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
function sprogarray(array $array, array $fields, $fallback_clang_id = 0, $separator = '_')
{
    foreach ($fields as $field) {
        $array[$field] = sprogvalue($array, $field, $fallback_clang_id, $separator);
    }
    return $array;
}

$filters = $this->getProperty('filter');
$filters = \rex_extension::registerPoint(new \rex_extension_point('SPROG_FILTER', $filters));

$registeredFilters = [];
if (count($filters) > 0) {
    foreach ($filters as $filter) {
        $instance = new $filter();
        $registeredFilters[$instance->name()] = $instance;
    }
}
\rex::setProperty('SPROG_FILTER', $registeredFilters);

if (!rex::isBackend()) {
    \rex_extension::register('OUTPUT_FILTER', '\Sprog\Extension::replaceWildcards', rex_extension::NORMAL);
}

if (rex::isBackend() && rex::getUser()) {
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
                    \rex_config::set('sprog', 'clang_base', rex_request('clang_base', 'array'));
                }
            }
        }

        if (rex::getUser()->isAdmin() || rex::getUser()->hasPerm('sprog[wildcard]')) {
            $page = \rex_be_controller::getPageObject('sprog/wildcard');

            if (Wildcard::isClangSwitchMode()) {
                $clang_id = str_replace('clang', '', rex_be_controller::getCurrentPagePart(3));
                $page->setSubPath(rex_path::addon('sprog', 'pages/wildcard.clang_switch.php'));
                $clangAll = \rex_clang::getAll();
                $clangBase = $this->getConfig('clang_base');
                // Alle Sprachen die eine andere Basis haben, nicht in der Navigation erscheinen lassen
                foreach ($clangAll as $clang) {
                    if (isset($clangBase[$clang->getId()]) && $clangBase[$clang->getId()] != $clang->getId()) {
                        unset($clangAll[$clang->getId()]);
                    }
                }
                foreach ($clangAll as $id => $clang) {
                    if (rex::getUser()->getComplexPerm('clang')->hasPerm($id)) {
                        $page->addSubpage((new rex_be_page('clang'.$id, $clang->getName()))
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

    /*
    |--------------------------------------------------------------------------
    | PAGE_BODY_ATTR
    |--------------------------------------------------------------------------
    */
    rex_extension::register('PAGE_BODY_ATTR', function (\rex_extension_point $ep) {
        $subject = $ep->getSubject();
        $subject['class'][] = 'rex-page-sprog-copy-popup';
        $ep->setSubject($subject);
    });

    /*
    |--------------------------------------------------------------------------
    | Stylesheets and Javascripts
    |--------------------------------------------------------------------------
    */
    if (rex_be_controller::getCurrentPagePart(1) == 'sprog.copy.structure_content_popup' ||
        rex_be_controller::getCurrentPagePart(1) == 'sprog.copy.structure_metadata_popup') {
        rex_view::addJsFile($this->getAssetsUrl('js/handlebars.min.js?v='.$this->getVersion()));
        rex_view::addJsFile($this->getAssetsUrl('js/timer.jquery.min.js?v='.$this->getVersion()));
    }

    rex_view::addCssFile($this->getAssetsUrl('css/sprog.css?v='.$this->getVersion()));
    rex_view::addJsFile($this->getAssetsUrl('js/sprog.js?v='.$this->getVersion()));
}
