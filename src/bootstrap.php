<?php

declare(strict_types=1);

use App\Environment;

require_once dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Load `.env` unconditionally.
 *
 * `vlucas/phpdotenv` is a production dependency on purpose. It used to be a dev dependency guarded by
 * `class_exists()`, which meant a production `composer install --no-dev` silently ignored `.env` and
 * every setting fell back to its default. Loading it here, from a fixed path, is also what guarantees
 * that PHP-FPM and the cron worker resolve the *same* configuration: both run as the same user and read
 * the same file. Run `./yii kf:health` on both to compare the resulting fingerprints.
 *
 * `createImmutable()` leaves any variable already present in the process environment untouched, so
 * server-level configuration (FPM pool `env[]`, systemd `EnvironmentFile=`) still wins where it is used.
 */
\Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

Environment::prepare();
