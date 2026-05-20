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
 * BC-Alias für die v1-API. Implementierung in `Sprog\Compat\Sync`.
 *
 * @deprecated since 2.0 — verwende Sprog\Compat\Sync; in v3.0 wird die Sync-
 *             Logik vom neuen Source-Layer (Sprog\Source\*) ersetzt.
 */
class Sync extends Compat\Sync {}
