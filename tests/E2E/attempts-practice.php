<?php

declare(strict_types=1);

/** How many attempts the practice fixture's store holds. Read-only; used to prove none are duplicated. */

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

echo (string) $c->createCommand(
    'SELECT COUNT(*) FROM order_testing_attempts WHERE store_source_id = :s',
    [':s' => 987655920],
)->queryScalar(), "\n";
