<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/app')
    ->in(__DIR__ . '/packages')
    ->in(__DIR__ . '/scripts');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS3x0' => true,
        'declare_strict_types' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'blank_line_before_statement' => ['statements' => ['break', 'continue', 'do', 'for', 'foreach', 'if', 'return', 'throw', 'try', 'switch', 'while']],
    ])
    ->setFinder($finder);
