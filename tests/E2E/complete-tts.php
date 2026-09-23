<?php

declare(strict_types=1);

/**
 * Finish a queued rendition the way the worker would, without calling a provider.
 *
 * The web tier only ever enqueues — rendering happens in `kf:audio:tts-worker`, which would spend
 * money. So the browser test drives the request and this stands in for the render: the same columns,
 * the same hash and render key the service would have compared against, and a file of silence.
 *
 * Usage: php complete-tts.php <jobPublicId>
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\AudioToText\Application\EffectiveConversationReader;
use App\AudioToText\Application\RecordingVoiceReader;
use App\AudioToText\Application\Speaker\SpeakerSegmentsDecoder;
use App\AudioToText\Application\Tts\TtsRenderKey;
use App\AudioToText\Application\Tts\TtsScriptBuilder;
use App\AudioToText\Application\Tts\TtsSourceDigest;
use App\AudioToText\Domain\Tts\TtsOutputType;
use App\Environment;
use App\Shared\Domain\Clock\SystemClock;
use App\Shared\Infrastructure\Db\DbConnectionFactory;
use App\Shared\Infrastructure\Db\DbParams;
use App\AudioToText\Infrastructure\DbAudioConversationRepository;
use App\AudioToText\Infrastructure\DbTranscriptionJobRepository;
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

$publicId = $argv[1] ?? '';
$jobs = new DbTranscriptionJobRepository($c, new SystemClock());
$job = $jobs->findByPublicId($publicId);

if ($job === null) {
    fwrite(STDERR, "No such job: {$publicId}\n");
    exit(1);
}

$builder = new TtsScriptBuilder(
    new EffectiveConversationReader(new SpeakerSegmentsDecoder()),
    new RecordingVoiceReader(new DbAudioConversationRepository($c)),
);

$voice = $builder->voiceFor($job);
$script = $builder->build($job, TtsOutputType::Mixed);
$hash = TtsSourceDigest::for(TtsOutputType::Mixed, $script->utterances);

// The settings the app is running with, so the render key matches what the page will compute.
$params = require dirname(__DIR__, 2) . '/config/common/params.php';
$ttsParams = $params['app/audio-deepgram-tts'];
$tts = new App\AudioToText\Application\Settings\TtsSettings(
    apiKey: new App\Shared\Domain\ValueObject\SecretValue($ttsParams['apiKey']),
    url: $ttsParams['url'],
    customerModel: $ttsParams['customerModel'],
    agentModel: $ttsParams['agentModel'],
    callerModel: $ttsParams['callerModel'],
    calleeModel: $ttsParams['calleeModel'],
    sampleRate: $ttsParams['sampleRate'],
    maxCharactersPerRequest: $ttsParams['maxCharactersPerRequest'],
    timeoutSeconds: $ttsParams['timeoutSeconds'],
    gapMilliseconds: $ttsParams['gapMilliseconds'],
    outputFormat: App\AudioToText\Domain\Tts\TtsOutputFormat::fromConfig($ttsParams['outputFormat']),
    maxAttempts: $ttsParams['maxAttempts'],
);

$renderKey = TtsRenderKey::for($tts, TtsOutputType::Mixed, $voice);
$fileName = $job->publicId . '-mixed.wav';

// A second of silence, so the streaming endpoint has real bytes to serve.
$dir = dirname(__DIR__, 2) . '/runtime/audio-to-text/tts';
@mkdir($dir, 0o775, true);
$samples = 24000;
$data = str_repeat("\0\0", $samples);
$wav = 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 24000, 48000, 2, 16)
    . 'data' . pack('V', strlen($data)) . $data;
file_put_contents($dir . '/' . $fileName, $wav);

$c->createCommand()->update('{{%audio_tts_renditions}}', [
    'status' => 'READY',
    'file_name' => $fileName,
    'file_hash' => $hash,
    'file_render_key' => $renderKey,
    'file_bytes' => strlen($wav),
    'character_count' => 100,
    'request_count' => 1,
    'model_customer' => $voice === null ? $tts->customerModel : null,
    'model_agent' => $voice === null ? $tts->agentModel : null,
    'updated_at' => gmdate('Y-m-d H:i:s'),
], ['job_id' => $job->id, 'output_type' => 'MIXED'])->execute();

echo json_encode(['job' => $publicId, 'voice' => $voice?->value, 'hash' => $hash], JSON_THROW_ON_ERROR), "\n";
