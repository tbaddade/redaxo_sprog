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

class Title extends Filter
{
    /**
     * {@inheritdoc}
     */
    public function name()
    {
        return 'title';
    }

    /**
     * {@inheritdoc}
     */
    public function fire($value, $arguments)
    {
        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }
}
