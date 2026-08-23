<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS3x0' => true,
        'declare_strict_types' => true,
        'ordered_imports' => [
            'sort_algorithm' => 'alpha',
        ],
        'blank_line_before_statement' => ['statements' => ['break', 'continue', 'return', 'throw', 'try', 'switch']],
    ])
    ->setFinder(
        Finder::create()
            ->in([
                __DIR__ . '/app',
                __DIR__ . '/packages',
                __DIR__ . '/scripts',
                __DIR__ . '/tests',
            ])
            ->exclude(['vendor', 'node_modules']),
    );
