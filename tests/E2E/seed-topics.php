<?php

declare(strict_types=1);

/**
 * One store, two orders and two topics — the smallest arrangement in which the topic interface can be
 * wrong in all the ways it has been.
 *
 * Deliberately included:
 *
 * - **two topics, with and without a description**, so the edit dialog can be opened on one and then
 *   the other: the bug this fixture exists for was one topic's values appearing under another's name;
 * - **one assigned order and one unassigned**, so the filter has something to narrow *to*;
 * - **a recording whose order was never mirrored**, which is the live `#111` case — the row must offer
 *   no checkbox and no Set Topic button, and no browser test can see that without such a row.
 *
 * No audio file is written: these checks are about the topic controls, and a row renders from its
 * conversation whether or not there are bytes behind it. Nothing here reaches any real store — the
 * store has no host at all, so no demo link is even offered.
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

const ADMIN = '__kf_e2e_topics_admin__';
const PASSWORD = 'E2ETopicsPassw0rd!secure';
const STORE = 987655930;
const SLUG = 'kf-e2e-topics-store';
const ASSIGNED_ORDER = 16800931;
const FREE_ORDER = 16800932;
/** Named by a recording but never mirrored: the row that can carry no topic. */
const ORPHAN_ORDER = 16800939;
const TOPIC_A = 'ZZ E2E Alpha Topic';
const TOPIC_B = 'ZZ E2E Beta Topic';

require __DIR__ . '/purge-topics.php';

$now = gmdate('Y-m-d H:i:s');

$c->createCommand()->insert('{{%admin_users}}', [
    'username' => ADMIN,
    'password_hash' => (new NativePasswordHasher())->hash(PASSWORD),
    'created_at' => $now,
    'updated_at' => $now,
])->execute();
$adminId = (int) $c->createCommand('SELECT id FROM admin_users WHERE username = :u', [':u' => ADMIN])->queryScalar();

$c->createCommand()->insert('{{%order58_stores}}', [
    'source_id' => STORE,
    'name' => 'KF E2E Topics Store',
    'active' => 1,
    'sync_hash' => str_repeat('0', 64),
    'synced_at' => $now,
    'created_at' => $now,
    'updated_at' => $now,
])->execute();

$c->createCommand()->insert('{{%knowledge_bases}}', [
    'name' => 'KF E2E Topics Store',
    'slug' => SLUG,
    'source_system' => 'order58',
    'source_store_id' => STORE,
    'source_name' => 'KF E2E Topics Store',
    'source_active' => 1,
    'created_at' => $now,
    'updated_at' => $now,
])->execute();

foreach ([ASSIGNED_ORDER, FREE_ORDER] as $orderId) {
    $payload = (string) json_encode(['id' => (string) $orderId, 'status' => 'completed']);

    $c->createCommand()->insert('{{%order58_orders}}', [
        'account_id' => STORE,
        'source_order_id' => $orderId,
        'payload_json' => $payload,
        'content_hash' => hash('sha256', $payload),
        'synced_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ])->execute();
}

// Three recordings: two with a mirrored order behind them, one without.
$conversations = [
    ASSIGNED_ORDER => str_repeat('a', 32),
    FREE_ORDER => str_repeat('b', 32),
    ORPHAN_ORDER => str_repeat('9', 32),
];

foreach ($conversations as $orderId => $publicId) {
    $c->createCommand()->insert('{{%audio_conversations}}', [
        'public_id' => $publicId,
        'store_source_id' => STORE,
        'mode' => 'COMMON',
        'recording_type' => 'MIXED',
        'order_id' => (string) $orderId,
        'generate_ai_audio' => 0,
        'uploaded_by_admin_id' => $adminId,
        'created_at' => $now,
    ])->execute();

    $conversationId = (int) $c->createCommand(
        'SELECT id FROM audio_conversations WHERE public_id = :p',
        [':p' => $publicId],
    )->queryScalar();

    $c->createCommand()->insert('{{%audio_transcription_jobs}}', [
        'public_id' => substr(str_repeat((string) $orderId, 8), 0, 32),
        'conversation_id' => $conversationId,
        'uploaded_by_admin_id' => $adminId,
        'original_filename' => 'topics.wav',
        'source_role' => 'COMMON',
        'status' => 'COMPLETED',
        'processing_stage' => 'COMPLETED',
        'transcription_provider' => 'WHISPER',
        'duration_seconds' => 6.0,
        'transcript' => 'Hello.',
        'review_count' => 0,
        'created_at' => $now,
        'completed_at' => $now,
    ])->execute();
}

// Two topics: one described, one not, so the details dialog can be checked both ways.
$topics = [
    TOPIC_A => 'The customer changes a dish after the order is placed.',
    TOPIC_B => null,
];
$ids = [];

foreach ($topics as $name => $description) {
    $c->createCommand()->insert('{{%practice_topics}}', [
        'name' => $name,
        'name_key' => mb_strtolower($name),
        'description' => $description,
        'status' => 'ACTIVE',
        'created_by_admin_id' => $adminId,
        'created_at' => $now,
        'updated_at' => $now,
    ])->execute();

    $ids[$name] = (int) $c->getLastInsertID();
}

$c->createCommand()->insert('{{%practice_order_topics}}', [
    'store_source_id' => STORE,
    'source_order_id' => ASSIGNED_ORDER,
    'topic_id' => $ids[TOPIC_A],
    'assigned_by_type' => 'ADMIN',
    'assigned_by_id' => $adminId,
    'created_at' => $now,
    'updated_at' => $now,
])->execute();

echo json_encode([
    'admin' => ADMIN,
    'password' => PASSWORD,
    'store' => STORE,
    'assignedOrder' => ASSIGNED_ORDER,
    'freeOrder' => FREE_ORDER,
    'orphanOrder' => ORPHAN_ORDER,
    'topicA' => ['id' => $ids[TOPIC_A], 'name' => TOPIC_A, 'description' => $topics[TOPIC_A]],
    'topicB' => ['id' => $ids[TOPIC_B], 'name' => TOPIC_B],
], JSON_PRETTY_PRINT), "\n";
