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
     * Provide the commands of the search.
     *
     * @return array
     */
    abstract function name();

    /**
     * Execute the filter
     *
     * @param  Command $command
     * @return Result
     */
    abstract function fire(Command $command);
}
