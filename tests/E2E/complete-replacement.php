<?php

declare(strict_types=1);

/**
 * Finish a queued recording the way the transcription worker would, without running Whisper.
 *
 * The browser test uploads a real replacement through the real endpoint, which leaves a QUEUED job —
 * and the property under test is what happens when that job *completes*, which is the worker's status
 * write. Running the actual worker would need ffmpeg, a model and a minute; this writes the same
 * columns the worker writes and nothing else, so the swap under test is the real one.
 *
 * Nothing here is used by the application. It exists so a browser test can reach the state that
 * matters without a transcription.
 *
 * Usage: php complete-replacement.php <storeSourceId> <orderId> <recordingType>
 *        — completes the newest QUEUED job of that recording type in that order.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Environment;
use App\Shared\Infrastructure\Db\DbConnectionFactory;
use App\Shared\Infrastructure\Db\DbParams;
use Yiisoft\Cache\ArrayCache;
use Yiisoft\Db\Cache\SchemaCache;

Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
Environment::prepare();

$store = (int) ($argv[1] ?? 0);
$order = (string) ($argv[2] ?? '');
$type = (string) ($argv[3] ?? '');

if ($store === 0 || $order === '' || $type === '') {
    fwrite(STDERR, "Usage: php complete-replacement.php <storeSourceId> <orderId> <recordingType>\n");

    exit(1);
}

$connection = (new DbConnectionFactory(
    new DbParams(
        host: Environment::string('DB_HOST'),
        port: Environment::int('DB_PORT'),
        name: Environment::string('DB_NAME'),
        user: Environment::string('DB_USER'),
        password: Environment::string('DB_PASSWORD'),
        charset: Environment::string('DB_CHARSET'),
        socket: Environment::string('DB_SOCKET'),
    ),
    new SchemaCache(new ArrayCache()),
))->create();

$row = $connection->createCommand(
    'SELECT j.id FROM {{%audio_transcription_jobs}} j
     JOIN {{%audio_conversations}} c ON c.id = j.conversation_id
     WHERE c.store_source_id = :store AND c.order_id = :order AND c.recording_type = :type
       AND j.status = :queued
     ORDER BY j.id DESC LIMIT 1',
    [':store' => $store, ':order' => $order, ':type' => $type, ':queued' => 'QUEUED'],
)->queryScalar();

if ($row === null || $row === false) {
    fwrite(STDERR, "No queued recording of that kind in that order.\n");

    exit(1);
}

// The replacement's own transcript, deliberately different from the one it replaces: a test that could
// not tell the two apart would pass whether or not the swap happened.
$segments = [
    [
        'start_ms' => 0,
        'end_ms' => 2000,
        'speaker' => 'A',
        'role' => 'CUSTOMER',
        'text' => 'This is the replacement recording.',
        'confidence' => 0.95,
        'approx' => false,
    ],
    [
        'start_ms' => 2400,
        'end_ms' => 4000,
        'speaker' => 'B',
        'role' => 'AGENT',
        'text' => 'Understood, the new one.',
        'confidence' => 0.95,
        'approx' => false,
    ],
];
$now = gmdate('Y-m-d H:i:s');

$connection->createCommand()->update('{{%audio_transcription_jobs}}', [
    'status' => 'COMPLETED',
    'processing_stage' => 'COMPLETED',
    'retained_audio_path' => 'source.wav',
    'duration_seconds' => 12.0,
    'transcript' => 'This is the replacement recording. Understood, the new one.',
    'speaker_segments' => json_encode($segments, JSON_THROW_ON_ERROR),
    'customer_text' => 'This is the replacement recording.',
    'agent_text' => 'Understood, the new one.',
    'speaker_separation_status' => 'COMPLETED',
    'speaker_separation_method' => 'e2e',
    'speaker_role_confidence' => 0.95,
    'completed_at' => $now,
], ['id' => (int) $row])->execute();

echo "completed job {$row}\n";
