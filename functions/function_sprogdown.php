<?php

/**
 * Replaced some wildcards in given text.
 */
function sprogdown($text, $clang_id = null)
{
    return Wildcard::parse($text, $clang_id);
}