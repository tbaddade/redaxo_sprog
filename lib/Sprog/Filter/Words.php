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

class Words extends Filter
{
    /**
     * {@inheritdoc}
     */
    public function name()
    {
        return 'words';
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
        $words = (int) $parts[0];
        $end = isset($parts[1]) ? $parts[1] : '';

        preg_match('/^\s*+(?:\S++\s*+){1,'.$words.'}/u', $value, $matches);
        if (!isset($matches[0]) || mb_strlen($value) === mb_strlen($matches[0])) {
            return $value;
        }
        return rtrim($matches[0]).$end;
    }
}
