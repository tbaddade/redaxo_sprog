<?php

declare(strict_types=1);

use PhpCsFixer\Finder;
use Redaxo\PhpCsFixerConfig\Config;

$finder = (new Finder())
    ->in(__DIR__ . '/lib')
    ->in(__DIR__ . '/functions')
    ->in(__DIR__ . '/pages')
    ->append([
        __DIR__ . '/boot.php',
        __DIR__ . '/install.php',
        __DIR__ . '/update.php',
    ]);

return Config::redaxo5()
    ->setFinder($finder);
