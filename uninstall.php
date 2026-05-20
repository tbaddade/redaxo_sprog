<?php

declare(strict_types=1);

use Sprog\Schema\V1Schema;
use Sprog\Schema\V2Schema;

/*
 * Sprog Schema-Teardown.
 *
 * Gegenstück zu install.php. Räumt alle vom Addon angelegten Tabellen
 * sowie sämtliche rex_config.sprog.*-Keys ab. drop() ist idempotent
 * (DROP TABLE IF EXISTS), removeNamespace() ebenso.
 *
 * Reihenfolge: erst v2 (kann implizit auf v1-Daten verweisen, z.B. über
 * Migration-State in sprog_activity), dann v1.
 */

V2Schema::drop();
V1Schema::drop();

rex_config::removeNamespace('sprog');
