<?php

/**
 * This file is part of the Sprog package.
 *
 * @author (c) Thomas Blum <thomas@addoff.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sprog\Filter;

use Sprog\Filter;

use const MB_CASE_TITLE;

class Title extends Filter
{
    public function name(): string
    {
        return 'title';
    }

    public function fire(string $value, string $arguments): string
    {
        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }
}
