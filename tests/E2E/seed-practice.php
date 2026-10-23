<?php

declare(strict_types=1);

/**
 * One store, one order and one recording, arranged so Demo URL is actually offered.
 *
 * ## The host is deliberately real-looking and deliberately dead
 *
 * `DemoOrderUrl` only builds a link for a host under an allow-listed domain, so the fixture store must
 * sit under `order58.com` or the button never renders and there is nothing to click. It is a subdomain
 * that does not resolve, which is the point: the browser opens the second tab, the attempt is recorded
 * exactly as in production, and **no request ever reaches the client's live ordering system**. Nothing
 * is ordered, nothing is submitted, and no real store is held by an open attempt.
 *
 * ## Real bytes, because the player is the thing under test
 *
 * A row with no audio is a row the player is correctly withheld from, so the recording is written to
 * disk as a short tone. It is synthesised here rather than copied from the mirror: no customer audio is
 * involved at any point.
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

const ADMIN = '__kf_e2e_practice_admin__';
const PASSWORD = 'E2EPracticePassw0rd!secure';
const STORE = 987655920;
const SLUG = 'kf-e2e-practice-store';
const HOST = 'kf-e2e-practice-does-not-resolve.order58.com';
const ORDER = 16800921;
const PHONE = '19178530921';
const CONVERSATION = 'c0ffee00c0ffee00c0ffee00c0ffee00';
const JOB = 'f00dfacef00dfacef00dfacef00dface';

require __DIR__ . '/purge-practice.php';

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
    'name' => 'KF E2E Practice Store',
    'active' => 1,
    'host' => HOST,
    'sync_hash' => str_repeat('0', 64),
    'synced_at' => $now,
    'created_at' => $now,
    'updated_at' => $now,
])->execute();

$c->createCommand()->insert('{{%knowledge_bases}}', [
    'name' => 'KF E2E Practice Store',
    'slug' => SLUG,
    'source_system' => 'order58',
    'source_store_id' => STORE,
    'source_name' => 'KF E2E Practice Store',
    'source_active' => 1,
    'created_at' => $now,
    'updated_at' => $now,
])->execute();

// The mirrored order. Its phone is what completes the demo address, so without it the button is withheld.
$payload = (string) json_encode([
    'id' => (string) ORDER,
    'status' => 'completed',
    'type' => 'delivery',
    'data' => (string) json_encode(['reservation' => ['phone' => PHONE]]),
]);

$c->createCommand()->insert('{{%order58_orders}}', [
    'account_id' => STORE,
    'source_order_id' => ORDER,
    'reservation_phone' => PHONE,
    'type' => 'delivery',
    'payment_method' => 'cash',
    'status' => 'completed',
    'total_amount' => '10.00000',
    'payload_json' => $payload,
    'content_hash' => hash('sha256', $payload),
    'synced_at' => $now,
    'created_at' => $now,
    'updated_at' => $now,
    'items_status' => 'SYNCED',
    'items_synced_at' => $now,
])->execute();

$c->createCommand()->insert('{{%audio_conversations}}', [
    'public_id' => CONVERSATION,
    'store_source_id' => STORE,
    'mode' => 'COMMON',
    'recording_type' => 'MIXED',
    'order_id' => (string) ORDER,
    'generate_ai_audio' => 0,
    'uploaded_by_admin_id' => $adminId,
    'created_at' => $now,
])->execute();
$conversationId = (int) $c->createCommand(
    'SELECT id FROM audio_conversations WHERE public_id = :p',
    [':p' => CONVERSATION],
)->queryScalar();

// Six seconds of 440 Hz, written where `QueuedAudioStorage::retainedPathFor()` looks for it.
$dir = dirname(__DIR__, 2) . '/runtime/audio-to-text/recordings/' . JOB;

if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}

$rate = 8000;
$samples = $rate * 6;
$pcm = '';

for ($i = 0; $i < $samples; $i++) {
    $pcm .= pack('v', (int) (sin(2 * M_PI * 440 * $i / $rate) * 8000) & 0xFFFF);
}

$wav = 'RIFF' . pack('V', 36 + strlen($pcm)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16)
    . 'data' . pack('V', strlen($pcm)) . $pcm;

file_put_contents($dir . '/source.wav', $wav);

$c->createCommand()->insert('{{%audio_transcription_jobs}}', [
    'public_id' => JOB,
    'conversation_id' => $conversationId,
    'uploaded_by_admin_id' => $adminId,
    'original_filename' => 'practice.wav',
    'retained_audio_path' => 'source.wav',
    'source_role' => 'COMMON',
    'status' => 'COMPLETED',
    'processing_stage' => 'COMPLETED',
    'transcription_provider' => 'WHISPER',
    'duration_seconds' => 6.0,
    'transcript' => 'Hello, I would like to place an order.',
    'speaker_segments' => (string) json_encode([
        ['start_ms' => 0, 'end_ms' => 3000, 'speaker' => 'A', 'role' => 'CUSTOMER',
            'text' => 'Hello, I would like to place an order.', 'confidence' => 0.95, 'approx' => false],
        ['start_ms' => 3200, 'end_ms' => 5800, 'speaker' => 'B', 'role' => 'AGENT',
            'text' => 'Sure, pickup or delivery?', 'confidence' => 0.95, 'approx' => false],
    ]),
    'review_count' => 0,
    'created_at' => $now,
    'completed_at' => $now,
])->execute();

// A SECOND order, already held by somebody else, so the refusal path is reachable without a second
// login. `start()` resumes for the same operator and only refuses when the open attempt belongs to a
// different one — which is the distinction this row exists to create.
const HELD_ORDER = 16800922;

$heldPayload = (string) json_encode([
    'id' => (string) HELD_ORDER,
    'status' => 'completed',
    'type' => 'delivery',
    'data' => (string) json_encode(['reservation' => ['phone' => PHONE]]),
]);

$c->createCommand()->insert('{{%order58_orders}}', [
    'account_id' => STORE,
    'source_order_id' => HELD_ORDER,
    'reservation_phone' => PHONE,
    'type' => 'delivery',
    'payment_method' => 'cash',
    'status' => 'completed',
    'total_amount' => '10.00000',
    'payload_json' => $heldPayload,
    'content_hash' => hash('sha256', $heldPayload),
    'synced_at' => $now,
    'created_at' => $now,
    'updated_at' => $now,
    'items_status' => 'SYNCED',
    'items_synced_at' => $now,
])->execute();

// A conversation for it too, so the row renders with a recording and the only difference between the
// two orders is who holds the attempt.
$c->createCommand()->insert('{{%audio_conversations}}', [
    'public_id' => str_repeat('b', 32),
    'store_source_id' => STORE,
    'mode' => 'COMMON',
    'recording_type' => 'MIXED',
    'order_id' => (string) HELD_ORDER,
    'generate_ai_audio' => 0,
    'uploaded_by_admin_id' => $adminId,
    'created_at' => $now,
])->execute();

$heldConversationId = (int) $c->createCommand(
    'SELECT id FROM audio_conversations WHERE public_id = :p',
    [':p' => str_repeat('b', 32)],
)->queryScalar();

// Its own recording, with real bytes. Without audio the player would be withheld anyway and the
// refusal check below would prove nothing: the point is that the server's refusal is what stops it.
const HELD_JOB = 'beefbeefbeefbeefbeefbeefbeefbeef';

$heldDir = dirname(__DIR__, 2) . '/runtime/audio-to-text/recordings/' . HELD_JOB;

if (!is_dir($heldDir)) {
    mkdir($heldDir, 0775, true);
}

file_put_contents($heldDir . '/source.wav', $wav);

$c->createCommand()->insert('{{%audio_transcription_jobs}}', [
    'public_id' => HELD_JOB,
    'conversation_id' => $heldConversationId,
    'uploaded_by_admin_id' => $adminId,
    'original_filename' => 'held.wav',
    'retained_audio_path' => 'source.wav',
    'source_role' => 'COMMON',
    'status' => 'COMPLETED',
    'processing_stage' => 'COMPLETED',
    'transcription_provider' => 'WHISPER',
    'duration_seconds' => 6.0,
    'transcript' => 'Another call, already being tested by somebody else.',
    'review_count' => 0,
    'created_at' => $now,
    'completed_at' => $now,
])->execute();

// Held by an administrator who is not the one signing in. The id is far outside this fixture's own.
$c->createCommand()->insert('{{%order_testing_attempts}}', [
    'public_id' => bin2hex(random_bytes(16)),
    'store_source_id' => STORE,
    'source_order_id' => HELD_ORDER,
    'initiated_by_type' => 'ADMIN',
    'initiated_by_id' => $adminId + 99000,
    'status' => 'STARTED',
    'started_at' => $now,
    'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
    'created_at' => $now,
    'updated_at' => $now,
])->execute();

/*
 * Practitioners for the Practice Results table, enough of them to need a second page.
 *
 * Thirty, so the twenty-five-per-page boundary is exercised rather than described, with a spread of
 * try counts and scores — including one practitioner whose history is long enough to exceed what a
 * page can score, which is the state the table reports as "Not available" rather than guessing at.
 *
 * Agents are mirrored first so the table has real names to show and to search by: a row named
 * "Agent #7701" would pass a test and tell nobody whether the join works.
 */
$practitioners = [];

for ($i = 0; $i < 30; ++$i) {
    $adminIdForAgent = 97100 + $i;
    $username = sprintf('e2e_agent_%02d', $i);

    $c->createCommand()->insert('{{%order58_agents}}', [
        'admin_id' => $adminIdForAgent,
        'username' => $username,
        'status' => 'active',
        'user_type' => 'agent',
        'sync_hash' => str_repeat('e', 64),
        'synced_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ])->execute();

    $c->createCommand()->insert('{{%order_testing_attempts}}', [
        'public_id' => bin2hex(random_bytes(16)),
        'store_source_id' => STORE,
        'source_order_id' => ORDER,
        'initiated_by_type' => 'AGENT',
        'initiated_by_id' => $adminIdForAgent,
        'status' => 'EXPIRED',
        'started_at' => $now,
        'expires_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ])->execute();

    $attemptId = (int) $c->getLastInsertID();
    // One practitioner with a history longer than a page can score, and the rest ordinary.
    $tries = $i === 0 ? 70 : (1 + ($i % 4));

    for ($t = 0; $t < $tries; ++$t) {
        $demoOrderId = 18_000_000 + ($i * 100) + $t;

        $c->createCommand()->insert('{{%order_testing_demo_orders}}', [
            'public_id' => bin2hex(random_bytes(16)),
            'store_source_id' => STORE,
            'source_order_id' => ORDER,
            'demo_order_id' => $demoOrderId,
            'matched_attempt_id' => $attemptId,
            'order_created_at' => 1_791_000_000 + ($i * 10_000) + ($t * 600),
            'total_amount' => '12.50000',
            'source_filename' => 'e2e.json',
            'content_hash' => hash('sha256', (string) $demoOrderId),
            'raw_payload' => (string) json_encode([
                'id' => $demoOrderId,
                'total_amount' => '12.50000',
                'type' => 'take-out',
                'payment_method' => 'cash',
            ]),
            'imported_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    $practitioners[] = $adminIdForAgent;
}

// And two results nobody can be shown to have created, for the Unmatched section.
foreach ([0, 1] as $n) {
    $demoOrderId = 18_900_000 + $n;

    $c->createCommand()->insert('{{%order_testing_demo_orders}}', [
        'public_id' => bin2hex(random_bytes(16)),
        'store_source_id' => STORE,
        'source_order_id' => ORDER,
        'demo_order_id' => $demoOrderId,
        'matched_attempt_id' => null,
        'order_created_at' => 1_792_000_000 + $n,
        'total_amount' => '9.90000',
        'source_filename' => 'e2e.json',
        'content_hash' => hash('sha256', 'unmatched' . $demoOrderId),
        'raw_payload' => (string) json_encode(['id' => $demoOrderId, 'total_amount' => '9.90000']),
        'imported_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ])->execute();
}

echo json_encode([
    'admin' => ADMIN,
    'password' => PASSWORD,
    'store' => STORE,
    'order' => ORDER,
    'heldOrder' => HELD_ORDER,
    'job' => JOB,
    'storeUrl' => '/order-testing/store/' . STORE,
    'resultsUrl' => '/order-testing/store/' . STORE . '/order/' . ORDER . '/demo-orders',
    'practitioners' => $practitioners,
    'audioUrl' => '/audio-to-text/job/' . JOB . '/original/file',
], JSON_PRETTY_PRINT), "\n";
