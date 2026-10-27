<?php

declare(strict_types=1);

/**
 * One store with Case Order call recordings, for the real-browser playback check.
 *
 * Four orders: one recording, three recordings in one call, nothing, and one whose fixture answers 404.
 * The links point at the provider's host, but the server under test must run with
 * `ORDER58_RECORDING_STREAM_SOURCE=fixture`, so every one of them is answered from
 * `tests/_data/call-recordings` and **no request leaves the machine**. The runner refuses to start
 * otherwise. No customer audio is involved: the fixture is a synthetic tone.
 *
 * Prints the fixture as JSON for case-recordings.js.
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

const ADMIN = '__kf_e2e_case_recordings_admin__';
const PASSWORD = 'E2ECaseRecordingsPassw0rd!secure';
const STORE = 987655995;
const STORE_NAME = 'E2E Case Recording Store';

require __DIR__ . '/purge-case-recordings.php';

$now = gmdate('Y-m-d H:i:s');

$c->createCommand()->insert('{{%admin_users}}', [
    'username' => ADMIN,
    'password_hash' => (new NativePasswordHasher())->hash(PASSWORD),
    'created_at' => $now,
    'updated_at' => $now,
])->execute();

$c->createCommand()->insert('{{%order58_stores}}', [
    'source_id' => STORE,
    'name' => STORE_NAME,
    'active' => 1,
    'sync_hash' => str_repeat('0', 64),
    'synced_at' => $now,
    'created_at' => $now,
    'updated_at' => $now,
])->execute();

$c->createCommand()->insert('{{%knowledge_bases}}', [
    'name' => STORE_NAME,
    'slug' => 'kf-e2e-case-recordings',
    'source_system' => 'order58',
    'source_store_id' => STORE,
    'source_name' => STORE_NAME,
    'source_active' => 1,
    'created_at' => $now,
    'updated_at' => $now,
])->execute();

// order => [session, number of links]; null = no recording at all.
$orders = [
    919900701 => ['919900711', 1],
    919900702 => ['919900712', 3],
    919900703 => null,
    919900704 => ['919900404', 1],
];

$recordings = [];
$age = 0;

foreach ($orders as $order => $call) {
    $c->createCommand()->insert('{{%order58_orders}}', [
        'account_id' => STORE,
        'source_order_id' => $order,
        'payload_json' => '{}',
        'content_hash' => str_pad((string) $order, 64, '0', STR_PAD_LEFT),
        'source_created_at' => 1_790_000_000 - (++$age) * 60,
        'synced_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ])->execute();

    if ($call === null) {
        continue;
    }

    [$session, $count] = $call;

    $c->createCommand()->insert('{{%order58_call_sessions}}', [
        'store_source_id' => STORE,
        'source_order_id' => $order,
        'call_session_id' => $session,
        'created_at' => $now,
        'updated_at' => $now,
    ])->execute();

    $sessionRow = (int) $c->getLastInsertID();

    for ($position = 0; $position < $count; ++$position) {
        $url = 'https://order58.xrainbow.com/api/external/recording/fetch/' . $session
            . '?time=2026-10-27&company=SWCC&name=' . $order . '-15550001111-' . $session . '-' . $position;

        $c->createCommand()->insert('{{%order58_call_recordings}}', [
            'session_id' => $sessionRow,
            'position' => $position,
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        $recordings[(string) $order][] = (int) $c->getLastInsertID();
    }
}

echo json_encode([
    'admin' => ADMIN,
    'password' => PASSWORD,
    'store' => STORE,
    'storeName' => STORE_NAME,
    'one' => 919900701,
    'three' => 919900702,
    'none' => 919900703,
    'gone' => 919900704,
    'recordings' => $recordings,
    'fixtureBytes' => filesize(dirname(__DIR__) . '/_data/call-recordings/default.wav'),
], JSON_PRETTY_PRINT) . "\n";
