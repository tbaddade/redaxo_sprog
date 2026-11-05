<?php

/**
 * This file is part of the Sprog package.
 *
 * @author (c) Thomas Blum <thomas@addoff.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

// Zentraler Seitentitel für alle sprog-Subpages; die Subpages rendern nur ihren Inhalt.
echo rex_view::title(rex_i18n::msg('sprog_title'));

rex_be_controller::includeCurrentPageSubPath();
