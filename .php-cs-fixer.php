<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

ini_set('memory_limit', '512M');

$root = __DIR__;
$finder = (new Finder())
    ->in([
        $root . '/config',
        $root . '/src',
        $root . '/tests',
    ])
    // Codeception regenerates these actor traits on every run and they are gitignored, so linting them
    // makes the style check fail on any machine that has run the tests.
    ->exclude('Support/_generated')
    ->append([
        $root . '/public/index.php',
    ]);

return (new Config())
    ->setCacheFile(__DIR__ . '/runtime/cache/.php-cs-fixer.cache')
    ->setParallelConfig(ParallelConfigFactory::detect())
    ->setRules([
        '@PER-CS' => true,
        'no_unused_imports' => true,
    ])
    ->setFinder($finder);
