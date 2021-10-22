<?php

/**
 * Returns a field with the suffix of the current clang id.
 */
function sprogfield($field, $separator = '_')
{
    return $field.$separator.rex_clang::getCurrentId();
}