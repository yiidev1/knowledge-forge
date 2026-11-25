<?php

declare(strict_types=1);

/**
 * An administrator for the Recent Calls browser check — nothing else is needed: the page's calls and
 * recordings come from the repository's sample files (`?source=fixture`, development and test only).
 *
 *   php tests/E2E/seed-recent-calls.php > tests/E2E/fixtures.json
 *   php tests/E2E/seed-recent-calls.php --cleanup
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Auth\Infrastructure\NativePasswordHasher;
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

const ADMIN = '__kf_e2e_recent_calls_admin__';
const PASSWORD = 'E2ERecentCallsPassw0rd!secure';

// Scoped to this fixture's own administrator; never a blanket delete.
$c->createCommand()->delete('{{%admin_users}}', ['username' => ADMIN])->execute();

if (in_array('--cleanup', $argv, true)) {
    echo "recent calls fixture removed\n";

    return;
}

$now = gmdate('Y-m-d H:i:s');
$c->createCommand()->insert('{{%admin_users}}', [
    'username' => ADMIN,
    'password_hash' => (new NativePasswordHasher())->hash(PASSWORD),
    'created_at' => $now,
    'updated_at' => $now,
])->execute();

echo json_encode(['admin' => ADMIN, 'password' => PASSWORD], JSON_THROW_ON_ERROR) . "\n";
