<?php

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