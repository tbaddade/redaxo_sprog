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

use rex_markdown;
use Sprog\Filter;

class Markdown extends Filter
{
    public function name(): string
    {
        return 'markdown';
    }

    public function fire(string $value, string $arguments): string
    {
        return rex_markdown::factory()->parse($value);
    }
}
