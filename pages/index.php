<?php

/**
 * This file is part of the Sprog package.
 *
 * @author (c) Thomas Blum <thomas@addoff.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

//$subpage = rex_be_controller::getCurrentPagePart(2);

echo rex_view::title(rex_i18n::msg('sprog_title'));

//include rex_be_controller::getCurrentPageObject()->getSubPath();
rex_be_controller::includeCurrentPageSubPath();
