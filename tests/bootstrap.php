<?php

declare(strict_types=1);

// Load .env for the test run exactly as the application does, so integration tests resolve the same
// database configuration as the running app. Real process environment still wins, so CI can override.
\Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

App\Environment::prepare();
