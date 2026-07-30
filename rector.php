<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Class_\InlineConstructorDefaultToPropertyRector;
use Rector\Config\RectorConfig;
use Rector\Php74\Rector\Closure\ClosureToArrowFunctionRector;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\Php80\Rector\Class_\StringableForToStringRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpSets(php82: true)
    ->withRules([
        InlineConstructorDefaultToPropertyRector::class,
    ])
    ->withSkip([
        ClosureToArrowFunctionRector::class,
        ReadOnlyPropertyRector::class,
        // SecretValue::__toString() exists precisely to THROW, so that interpolating a credential into
        // a log line or a template is a hard error. Declaring Stringable would advertise the opposite
        // — that the object is safe to stringify — so the rule is skipped for that file alone and
        // still applies everywhere else.
        StringableForToStringRector::class => [
            __DIR__ . '/src/Shared/Domain/ValueObject/SecretValue.php',
        ],
    ]);
