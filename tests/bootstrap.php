<?php

/**
 * PHPUnit-Bootstrap für Sprog.
 *
 * In Produktion bringt REDAXO seinen eigenen Klassen-Loader mit; das Composer-
 * Autoload-File wird im post-install-Hook explizit gelöscht, damit beide Loader
 * nicht in Konflikt geraten. Für die Tests brauchen wir aber sowohl die
 * Composer-deps (PHPUnit, Symfony Serializer …) als auch die Sprog-Klassen.
 *
 * Wir laden daher zwei Loader nacheinander:
 *   1. vendor/autoload.php — für PHPUnit + Symfony etc.
 *   2. einen schlanken PSR-4-Loader für den Sprog\-Namespace, der auf
 *      lib/Sprog/* zeigt.
 *
 * REDAXO-Stubs werden nicht geladen — die Test-Suite zielt bewusst auf die
 * Klassen, die ohne REDAXO-Laufzeit funktionieren. Service-Tests mit
 * rex_sql-Mocking sind eine separate, spätere Tranche.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Sprog\\')) {
        return;
    }
    $path = __DIR__ . '/../lib/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
