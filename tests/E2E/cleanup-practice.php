<?php

declare(strict_types=1);

/** Removes everything the practice fixture creates. Safe to run at any time. */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Environment;
use App\Shared\Infrastructure\Db\DbConnectionFactory;
use App\Shared\Infrastructure\Db\DbParams;
use Yiisoft\Cache\ArrayCache;
use Yiisoft\Db\Cache\SchemaCache;

Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
Environment::prepare();

$c = (new DbConnectionFactory(new DbParams(
    host: Environment::string('DB_HOST'),
    port: Environment::int('DB_PORT'),
    name: Environment::string('DB_NAME'),
    user: Environment::string('DB_USER'),
    password: Environment::string('DB_PASSWORD'),
    charset: Environment::string('DB_CHARSET'),
    socket: Environment::string('DB_SOCKET'),
), new SchemaCache(new ArrayCache())))->create();

const ADMIN = '__kf_e2e_practice_admin__';
const STORE = 987655920;
const SLUG = 'kf-e2e-practice-store';
const JOB = 'f00dfacef00dfacef00dfacef00dface';

require __DIR__ . '/purge-practice.php';

echo "practice fixture removed\n";
