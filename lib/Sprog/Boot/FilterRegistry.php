<?php

declare(strict_types=1);

namespace Sprog\Boot;

use rex;
use rex_extension;
use rex_extension_point;
use Sprog\Filter;

/**
 * Sammelt die in package.yml deklarierten Filter-Klassen, feuert den
 * SPROG_FILTER-Extension-Point, damit Dritt-Addons weitere Klassen
 * registrieren können, und instanziiert jede Klasse genau einmal pro
 * Request. Das Ergebnis landet als `name => instance`-Map auf
 * `rex::setProperty('SPROG_FILTER', …)`, wo der Wildcard-Pipeline-Code es
 * pro Match abruft.
 *
 * Bisher inline in boot.php; ausgelagert, um boot.php auf reine Hook-
 * Verdrahtung zu reduzieren.
 *
 * @phpstan-type FilterClass class-string<Filter>
 */
final class FilterRegistry
{
    /**
     * @param list<FilterClass> $configured
     */
    public static function publish(array $configured): void
    {
        /** @var list<FilterClass> $names */
        $names = rex_extension::registerPoint(new rex_extension_point('SPROG_FILTER', $configured));

        $instances = [];
        foreach ($names as $class) {
            $instance = new $class();
            $instances[$instance->name()] = $instance;
        }

        rex::setProperty('SPROG_FILTER', $instances);
    }
}
