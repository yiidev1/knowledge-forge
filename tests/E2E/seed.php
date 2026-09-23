<?php

declare(strict_types=1);

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

const ADMIN = '__kf_e2e_admin__';
const PASSWORD = 'E2EReviewPassw0rd!secure';
const STORE = 987655000;
const SLUG = 'kf-e2e-review-store';

$now = gmdate('Y-m-d H:i:s');

require __DIR__ . '/purge.php';

$c->createCommand()->insert('{{%admin_users}}', [
    'username' => ADMIN,
    'password_hash' => (new NativePasswordHasher())->hash(PASSWORD),
    'created_at' => $now,
    'updated_at' => $now,
])->execute();
$adminId = (int) $c->createCommand('SELECT id FROM admin_users WHERE username = :u', [':u' => ADMIN])->queryScalar();

$c->createCommand()->insert('{{%order58_stores}}', [
    'source_id' => STORE,
    'name' => 'KF E2E Review Store',
    'active' => 1,
    'sync_hash' => str_repeat('0', 64),
    'synced_at' => $now,
    'created_at' => $now,
    'updated_at' => $now,
])->execute();

$c->createCommand()->insert('{{%knowledge_bases}}', [
    'name' => 'KF E2E Review Store',
    'slug' => SLUG,
    'source_system' => 'order58',
    'source_store_id' => STORE,
    'source_name' => 'KF E2E Review Store',
    'source_active' => 1,
    'created_at' => $now,
    'updated_at' => $now,
])->execute();

/**
 * One upload of a named type, transcribed and diarized.
 *
 * Every type gets the *same* two-cluster `speaker_segments`, which is the point: the diarizer is
 * always asked, so a Caller recording really does come back with Speaker 1 and Speaker 2 inside it.
 * What the screens do with that is what these tests are about.
 */
$upload = static function (string $recordingType, string $orderId) use ($c, $adminId, $now): array {
    $conversation = bin2hex(random_bytes(16));
    $c->createCommand()->insert('{{%audio_conversations}}', [
        'public_id' => $conversation,
        'store_source_id' => STORE,
        'mode' => 'COMMON',
        'recording_type' => $recordingType,
        'order_id' => $orderId,
        'generate_ai_audio' => 0,
        'uploaded_by_admin_id' => $adminId,
        'created_at' => $now,
    ])->execute();
    $conversationId = (int) $c->createCommand(
        'SELECT id FROM audio_conversations WHERE public_id = :p',
        [':p' => $conversation],
    )->queryScalar();

    return [$conversation, $conversationId];
};

[$conversation, $conversationId] = $upload('MIXED', '99001122');

// Six turns, alternating, so previous/next are both available in the middle and absent at the ends.
$lines = [
    ['CUSTOMER', 'Hi, can I get a large pepperoni?', 0, 3000],
    ['AGENT', 'Sure. Pickup or delivery?', 3500, 5500],
    ['CUSTOMER', 'Pickup please.', 6000, 7200],
    ['CUSTOMER', 'And a garlic bread.', 7500, 9000],
    ['AGENT', 'That will be twelve fifty.', 9500, 12000],
    ['AGENT', 'Ready in fifteen minutes.', 12500, 14500],
];
$segments = [];
foreach ($lines as $i => [$role, $text, $start, $end]) {
    $segments[] = [
        'start_ms' => $start,
        'end_ms' => $end,
        'speaker' => $role === 'AGENT' ? 'B' : 'A',
        'role' => $role,
        'text' => $text,
        'confidence' => 0.95,
        'approx' => false,
    ];
}

$transcribe = static function (int $conversationId) use ($c, $adminId, $now, $lines, $segments): string {
    $job = bin2hex(random_bytes(16));
    $c->createCommand()->insert('{{%audio_transcription_jobs}}', [
        'conversation_id' => $conversationId,
        'source_role' => 'COMMON',
        'transcription_provider' => 'WHISPER',
        'public_id' => $job,
        'uploaded_by_admin_id' => $adminId,
        'status' => 'COMPLETED',
        'processing_stage' => 'COMPLETED',
        'original_filename' => 'kf-e2e.wav',
        'retained_audio_path' => 'source.wav',
        'duration_seconds' => 15.0,
        'transcript' => implode(' ', array_column($lines, 1)),
        'speaker_segments' => json_encode($segments, JSON_THROW_ON_ERROR),
        'customer_text' => 'Hi, can I get a large pepperoni? Pickup please. And a garlic bread.',
        'agent_text' => 'Sure. Pickup or delivery? That will be twelve fifty. Ready in fifteen minutes.',
        'speaker_separation_status' => 'COMPLETED',
        'speaker_separation_method' => 'e2e',
        'speaker_role_confidence' => 0.95,
        'created_at' => $now,
        'completed_at' => $now,
    ])->execute();

    return $job;
};

$job = $transcribe($conversationId);

// The same order as the mixed upload above, which is how a real call arrives: one Order ID with a
// mixed recording and each side beside it, sharing one row of the store page.
[$callerConversation, $callerId] = $upload('CALLER', '99001122');
[$calleeConversation, $calleeId] = $upload('CALLEE', '99001122');

// A mixed recording under its own order, touched by nothing else, so a test that needs an untouched
// eligible recording has one however many the tests before it have queued.
[$spareConversation, $spareId] = $upload('MIXED', '99002200');

echo json_encode([
    'admin' => ADMIN,
    'password' => PASSWORD,
    'store' => STORE,
    'conversation' => $conversation,
    'job' => $job,
    'callerJob' => $transcribe($callerId),
    'calleeJob' => $transcribe($calleeId),
    'spareConversation' => $spareConversation,
    'spareJob' => $transcribe($spareId),
], JSON_THROW_ON_ERROR), "\n";
