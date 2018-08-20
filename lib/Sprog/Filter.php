<?php

/**
 * This file is part of the Sprog package.
 *
 * @author (c) Thomas Blum <thomas@addoff.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sprog;

abstract class Filter
{
    /**
     * Returns the name of the filter.
     *
     * @return string
     */
    abstract public function name();

    /**
     * Execute the filter.
     *
     * @param string $value
     * @param string $arguments
     *
     * @return string
     */
    abstract public function fire($value, $arguments);
}
