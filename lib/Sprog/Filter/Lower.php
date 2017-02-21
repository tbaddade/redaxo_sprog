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

class Lower extends Filter
{
    /**
     * {@inheritdoc}
     */
    public function name()
    {
        return 'lower';
    }

    /**
     * {@inheritdoc}
     */
    public function fire($value, $arguments)
    {
        return mb_strtolower($value, 'UTF-8');
    }
}
