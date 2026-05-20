<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__ . '/Attribute',
        __DIR__ . '/Command',
        __DIR__ . '/DependencyInjection',
        __DIR__ . '/EventListener',
        __DIR__ . '/Rule',
        __DIR__ . '/Service',
        __DIR__ . '/Twig',
        __DIR__ . '/tests',
    ])
    ->append([
        __FILE__,
        __DIR__ . '/PimcoreAdvancedMaintenanceModeBundle.php',
    ]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS' => true,
        '@PER-CS:risky' => true,
        '@PHP83Migration' => true,
        '@PHP82Migration:risky' => true,
        'declare_strict_types' => true,
        'native_function_invocation' => false,
        'phpdoc_to_comment' => false,
        'global_namespace_import' => [
            'import_classes' => true,
            'import_constants' => false,
            'import_functions' => false,
        ],
    ])
    ->setFinder($finder);
