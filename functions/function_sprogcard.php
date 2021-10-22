<?php

/**
 * Replaced given wildcard.
 */
function sprogcard($wildcard, $clang_id = null)
{
    return Wildcard::get($wildcard, $clang_id);
}