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

/**
 * BC-Alias für die v1-API.
 *
 * Die eigentliche Implementierung lebt in `Sprog\Compat\Wildcard`. Diese Klasse
 * existiert nur, damit bestehender Aufrufer-Code (Templates, Module, Drittaddons,
 * der globale `Wildcard`-Alias aus pre-1.3) weiter funktioniert. Geht in v3.0
 * weg — neue Aufrufer sollen `Sprog\Service\WildcardLookupService` oder die
 * Helper aus `functions/sprog.php` nutzen.
 *
 * @deprecated since 2.0 — verwende Sprog\Compat\Wildcard oder Sprog\Service\WildcardLookupService.
 */
class Wildcard extends Compat\Wildcard {}
