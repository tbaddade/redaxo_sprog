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

class Limit extends Filter
{
    /**
     * {@inheritdoc}
     */
    public function name()
    {
        return 'limit';
    }

    /**
     * {@inheritdoc}
     */
    public function fire($value, $arguments)
    {
        if ($arguments == '') {
            return $value;
        }

        $parts = explode(',', $arguments);
        $limit = (int) $parts[0];
        $end = isset($parts[1]) ? $parts[1] : '';

        if (mb_strwidth($value, 'UTF-8') <= $limit) {
            return $value;
        }
        return rtrim(mb_strimwidth($value, 0, $limit, '', 'UTF-8')).$end;
    }
}
