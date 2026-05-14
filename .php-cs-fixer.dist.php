<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__ . '/lib')
    ->in(__DIR__ . '/functions')
    ->in(__DIR__ . '/pages')
    ->append([
        __DIR__ . '/boot.php',
        __DIR__ . '/install.php',
        __DIR__ . '/update.php',
    ]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        '@PSR12:risky' => true,
        '@Symfony' => true,
        '@Symfony:risky' => true,

        // REDAXO-Codebase nutzt häufig globale Funktionen aus REDAXO-Core;
        // strikt verboten wäre zu invasiv für Pages.
        'native_function_invocation' => ['include' => ['@compiler_optimized']],

        // Wir favorisieren explizite Strict-Types-Deklaration in neuen Files.
        'declare_strict_types' => true,

        // Kurze Array-Syntax, einheitlich.
        'array_syntax' => ['syntax' => 'short'],

        // Yoda-Conditions sind in @Symfony aktiv — wir bevorzugen klassisch.
        'yoda_style' => ['equal' => false, 'identical' => false, 'less_and_greater' => false],

        // Doctrine-style Annotations sind in REDAXO-Code unüblich.
        'phpdoc_annotation_without_dot' => false,
    ])
    ->setFinder($finder);
