<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

$root = __DIR__;
return (new Configuration())
    ->disableComposerAutoloadPathScan()
    ->setFileExtensions(['php'])
    ->addPathToScan($root . '/src', isDev: false)
    ->addPathToScan($root . '/config', isDev: false)
    ->addPathToScan($root . '/public/index.php', isDev: false)
    ->addPathToScan($root . '/yii', isDev: false)
    ->addPathToScan($root . '/tests', isDev: true)
    // Codeception regenerates these actor traits on every run and they are gitignored, so the
    // extensions they happen to touch are not this application's dependencies.
    ->addPathToExclude($root . '/tests/Support/_generated')

    // Wired through the DI container rather than referenced by name in scanned code.
    ->ignoreErrorsOnPackage('psr/container', [ErrorType::UNUSED_DEPENDENCY])
    ->ignoreErrorsOnPackage('yiisoft/config', [ErrorType::UNUSED_DEPENDENCY])
    ->ignoreErrorsOnPackage('yiisoft/di', [ErrorType::UNUSED_DEPENDENCY])
    ->ignoreErrorsOnPackage('yiisoft/view', [ErrorType::UNUSED_DEPENDENCY])
    ->ignoreErrorsOnPackage('yiisoft/router-fastroute', [ErrorType::UNUSED_DEPENDENCY])

    // yiisoft/cache provides the Psr\SimpleCache implementation that yiisoft/db's schema cache needs at
    // runtime. Our source only references the PSR interface (config/common/di/db.php) and the concrete
    // ArrayCache in tests, so the analyser sees it as dev-only — but production genuinely needs it.
    ->ignoreErrorsOnPackage('yiisoft/cache', [ErrorType::PROD_DEPENDENCY_ONLY_IN_DEV])

    // Note: src/bootstrap.php is now scanned as PRODUCTION code. The template previously excluded it
    // and re-added it as dev, because it loaded phpdotenv — a dev-only package — behind a
    // class_exists() guard. That is precisely the bug that made `composer install --no-dev` silently
    // ignore .env in production. phpdotenv is a production dependency now, so the exclusion is gone and
    // this analyser enforces that bootstrap.php only ever uses production dependencies.

    // Used behind function_exists() in HealthChecker to name the OS user, with a get_current_user()
    // fallback. Optional at runtime, so it is deliberately not a hard requirement in composer.json.
    ->ignoreErrorsOnExtension('ext-posix', [ErrorType::SHADOW_DEPENDENCY])

    // ---------------------------------------------------------------------------------------------
    // Installed in Phase 1, first referenced in a later phase. Every entry below must disappear as its
    // phase lands; the Phase 9 review checks that this block is empty.
    // ---------------------------------------------------------------------------------------------
    ->ignoreErrorsOnPackages(
        [
            'yiisoft/validator',  // Phase 4: upload validation (Rule\File, Rule\Image) — used in web forms.
            'yiisoft/files',      // storage directory management, wired via config.
        ],
        [ErrorType::UNUSED_DEPENDENCY],
    )
    ->ignoreErrorsOnExtensions(
        [
            'ext-curl',      // Phase 5: HTTP transport for the OpenAI gateway.
        ],
        [ErrorType::UNUSED_DEPENDENCY],
    );
