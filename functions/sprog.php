<?php

use Sprog\Wildcard;

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


/**
 * Replaced given wildcard.
 */
function sprogcard($wildcard, $clang_id = null)
{
    return Wildcard::get($wildcard, $clang_id);
}


/**
 * Replaced some wildcards in given text.
 */
function sprogdown($text, $clang_id = null)
{
    return Wildcard::parse($text, $clang_id);
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
