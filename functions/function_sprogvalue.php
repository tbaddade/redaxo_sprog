<?php

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