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

class Format extends Filter
{
    public function name(): string
    {
        return 'format';
    }

    public function fire(string $value, string $arguments): string
    {
        if ('' == $arguments) {
            return $value;
        }

        return vsprintf($value, explode(',', $arguments));
    }
}
