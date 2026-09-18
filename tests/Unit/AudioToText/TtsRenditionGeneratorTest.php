<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Application\Tts\GeneratedAudioStorage;
use App\AudioToText\Application\Tts\PcmAudio;
use App\AudioToText\Application\Tts\TtsRenditionGenerator;
use App\AudioToText\Application\Tts\TtsTextChunker;
use App\AudioToText\Domain\SpeakerRole;
use App\AudioToText\Domain\Tts\AudioEncoderInterface;
use App\AudioToText\Domain\Tts\SpeechSynthesizerInterface;
use App\AudioToText\Domain\Tts\TtsException;
use App\AudioToText\Domain\Tts\TtsOutputFormat;
use App\AudioToText\Domain\Tts\TtsOutputType;
use App\AudioToText\Domain\Tts\TtsScript;
use App\AudioToText\Domain\Tts\TtsUtterance;
use App\Tests\Support\AudioToTextSettingsFactory;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function is_dir;
use function random_bytes;
use function rmdir;
use function str_repeat;
use function strlen;
use function unlink;

/**
 * Generating one rendition end to end — with no network and no ffmpeg.
 *
 * That is possible precisely because chunks come back as raw PCM and are joined as bytes: the assembly
 * this test exercises is the real one, not a stand-in for it. Only the two edges are faked — the paid
 * provider and the single encode at the end.
 */
final class TtsRenditionGeneratorTest extends TestCase
{
    /**
     * Every job directory this test caused to be created.
     *
     * Tracked rather than guessed at, because the generator makes them itself — and a test that left
     * generated audio lying around in the shared test workspace would be one more thing nothing collects.
     *
     * @var list<string>
     */
    private array $createdJobDirectories = [];

    protected function tearDown(): void
    {
        $root = AudioToTextSettingsFactory::create()->transcription->aiAudioDirectory();

        foreach ($this->createdJobDirectories as $publicId) {
            $this->removeTree($root . '/' . $publicId);
        }

        $this->createdJobDirectories = [];
        @rmdir($root);
    }

    // ------------------------------------------------------------------ the happy path

    public function testItPublishesAFileNamedForTheTranscript(): void
    {
        [$generator, $synthesizer] = $this->build();

        $result = $generator->generate(
            $this->script(),
            TtsOutputType::Mixed,
            $this->jobPublicId(),
            'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789',
        );

        $this->assertSame('ai-mixed-abcdef01.mp3', $result->fileName);
        $this->assertSame(2, $result->requestCount);
        $this->assertSame(2, $synthesizer->calls);
    }

    /**
     * Each speaker gets their own voice, and the order is the conversation's.
     *
     * This is the feature: a trainee has to be able to tell who is talking without reading anything.
     */
    public function testEachSpeakerIsReadInTheirOwnVoice(): void
    {
        [$generator, $synthesizer] = $this->build();

        $generator->generate($this->script(), TtsOutputType::Mixed, $this->jobPublicId(), $this->hash());

        $this->assertSame(
            [
                ['Two egg foo young, no MSG.', 'aura-2-thalia-en'],
                ['Ready in 25 minutes.', 'aura-2-arcas-en'],
            ],
            $synthesizer->requests,
        );
    }

    /** A single-role file uses one voice for its whole length. */
    public function testASingleRoleRenditionUsesOneVoice(): void
    {
        [$generator, $synthesizer] = $this->build();

        $script = new TtsScript([
            new TtsUtterance(SpeakerRole::CUSTOMER, 'Two egg foo young.'),
            new TtsUtterance(SpeakerRole::CUSTOMER, 'No MSG please.'),
        ]);

        $generator->generate($script, TtsOutputType::Customer, $this->jobPublicId(), $this->hash());

        foreach ($synthesizer->requests as [$_text, $model]) {
            $this->assertSame('aura-2-thalia-en', $model);
        }
    }

    // ------------------------------------------------------------------ the assembly

    /**
     * Silence goes between **turns**, never between the chunks of one turn.
     *
     * A chunk boundary is an artefact of the provider's per-request ceiling, not something the speaker
     * did — a pause there would be audible in the middle of a sentence.
     */
    public function testSilenceSeparatesTurnsButNotChunksOfOneTurn(): void
    {
        $settings = AudioToTextSettingsFactory::create(
            ttsApiKey: 'k',
            ttsMaxCharactersPerRequest: 100,
            ttsGapMilliseconds: 100,
        );

        $synthesizer = new FakeSynthesizer(240);
        $encoder = new CapturingEncoder();
        $generator = new TtsRenditionGenerator(
            $settings,
            $synthesizer,
            $encoder,
            new GeneratedAudioStorage($settings),
            new TtsTextChunker(),
        );

        // One long turn (three chunks), then a second turn.
        $script = new TtsScript([
            new TtsUtterance(SpeakerRole::CUSTOMER, str_repeat('word ', 55)),
            new TtsUtterance(SpeakerRole::AGENT, 'Ready.'),
        ]);

        $generator->generate($script, TtsOutputType::Mixed, $this->jobPublicId(), $this->hash());

        $gap = strlen(PcmAudio::silence(100, 24000));
        $speech = $synthesizer->calls * 240;

        $this->assertGreaterThan(3, $synthesizer->calls, 'The fixture must actually be chunked.');
        $this->assertSame(
            $speech + $gap,
            strlen($encoder->rawBytes),
            'Exactly one gap, between the two turns — not one per chunk.',
        );
    }

    public function testNoGapIsInsertedForASingleRoleRendition(): void
    {
        [$generator, $synthesizer, $encoder] = $this->build();

        $script = new TtsScript([
            new TtsUtterance(SpeakerRole::CUSTOMER, 'One.'),
            new TtsUtterance(SpeakerRole::CUSTOMER, 'Two.'),
        ]);

        $generator->generate($script, TtsOutputType::Customer, $this->jobPublicId(), $this->hash());

        $this->assertSame($synthesizer->calls * 480, strlen($encoder->rawBytes));
    }

    public function testTheEncoderIsToldTheRateTheProviderWasAskedFor(): void
    {
        [$generator, , $encoder] = $this->build();

        $generator->generate($this->script(), TtsOutputType::Mixed, $this->jobPublicId(), $this->hash());

        $this->assertSame(24000, $encoder->sampleRate, 'A mismatch here plays the audio at the wrong speed.');
        $this->assertSame(TtsOutputFormat::Mp3, $encoder->format);
    }

    // ------------------------------------------------------------------ cleanup

    public function testWorkFilesAreRemovedOnSuccess(): void
    {
        [$generator] = $this->build();
        $publicId = $this->jobPublicId();

        $generator->generate($this->script(), TtsOutputType::Mixed, $publicId, $this->hash());

        $this->assertSame([], $this->workFiles($publicId), 'Scratch must not accumulate beside the output.');
    }

    /**
     * And on failure — which is the path that actually leaks, because the orphan sweep only collects
     * directories whose job row has gone, and a failed generation's job is perfectly healthy.
     */
    public function testWorkFilesAreRemovedWhenGenerationFails(): void
    {
        $settings = AudioToTextSettingsFactory::create(ttsApiKey: 'k');
        $publicId = $this->jobPublicId();

        $generator = new TtsRenditionGenerator(
            $settings,
            new FailingSynthesizer(),
            new CapturingEncoder(),
            new GeneratedAudioStorage($settings),
            new TtsTextChunker(),
        );

        try {
            $generator->generate($this->script(), TtsOutputType::Mixed, $publicId, $this->hash());
            $this->fail('The provider failure must surface.');
        } catch (TtsException) {
            $this->assertSame([], $this->workFiles($publicId));
        }
    }

    public function testAnEmptyScriptIsRefusedBeforeAnythingIsSpent(): void
    {
        [$generator, $synthesizer] = $this->build();

        try {
            $generator->generate(new TtsScript([]), TtsOutputType::Mixed, $this->jobPublicId(), $this->hash());
            $this->fail('Nothing to say must not become a request.');
        } catch (TtsException) {
            $this->assertSame(0, $synthesizer->calls, 'Not a single paid call for an empty transcript.');
        }
    }

    // ------------------------------------------------------------------ regeneration

    /**
     * A different transcript writes a different filename, so the previous file is still on disk and
     * still playable right up to the moment the row is repointed.
     */
    public function testRegeneratingWritesBesideThePreviousFileRatherThanOverIt(): void
    {
        [$generator] = $this->build();
        $publicId = $this->jobPublicId();

        $first = $generator->generate($this->script(), TtsOutputType::Mixed, $publicId, str_repeat('a', 64));
        $second = $generator->generate($this->script(), TtsOutputType::Mixed, $publicId, str_repeat('b', 64));

        $this->assertNotSame($first->fileName, $second->fileName);

        $storage = new GeneratedAudioStorage(AudioToTextSettingsFactory::create(ttsApiKey: 'k'));

        $this->assertNotNull($storage->pathFor($publicId, $first->fileName), 'The old file survives the new one.');
        $this->assertNotNull($storage->pathFor($publicId, $second->fileName));
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @return array{TtsRenditionGenerator, FakeSynthesizer, CapturingEncoder}
     */
    private function build(): array
    {
        $settings = AudioToTextSettingsFactory::create(ttsApiKey: 'k');

        $synthesizer = new FakeSynthesizer(480);
        $encoder = new CapturingEncoder();

        $generator = new TtsRenditionGenerator(
            $settings,
            $synthesizer,
            $encoder,
            new GeneratedAudioStorage($settings),
            new TtsTextChunker(),
        );

        return [$generator, $synthesizer, $encoder];
    }

    private function script(): TtsScript
    {
        return new TtsScript([
            new TtsUtterance(SpeakerRole::CUSTOMER, 'Two egg foo young, no MSG.'),
            new TtsUtterance(SpeakerRole::AGENT, 'Ready in 25 minutes.'),
        ]);
    }

    private function hash(): string
    {
        return str_repeat('f', 64);
    }

    private function jobPublicId(): string
    {
        $publicId = bin2hex(random_bytes(16));
        $this->createdJobDirectories[] = $publicId;

        return $publicId;
    }

    /**
     * @return list<string>
     */
    private function workFiles(string $publicId): array
    {
        $directory = AudioToTextSettingsFactory::create()->transcription->aiAudioDirectory() . '/' . $publicId;

        return glob($directory . '/ai-work-*') ?: [];
    }

    private function removeTree(string $directory): void
    {
        foreach (glob($directory . '/*') ?: [] as $entry) {
            is_dir($entry) ? $this->removeTree($entry) : @unlink($entry);
        }

        @rmdir($directory);
    }
}

/** Returns a fixed run of bytes per request, and remembers what it was asked to say. */
final class FakeSynthesizer implements SpeechSynthesizerInterface
{
    public int $calls = 0;

    /** @var list<array{string, string}> */
    public array $requests = [];

    public function __construct(private readonly int $bytesPerCall) {}

    public function synthesize(string $text, string $model): string
    {
        $this->calls++;
        $this->requests[] = [$text, $model];

        return str_repeat("\x01\x02", (int) ($this->bytesPerCall / 2));
    }

    public function assertReady(): void {}
}

final class FailingSynthesizer implements SpeechSynthesizerInterface
{
    public function synthesize(string $text, string $model): string
    {
        throw TtsException::rateLimited('too many requests');
    }

    public function assertReady(): void {}
}

/** Captures the raw PCM it was handed, and writes a plausible file so publication can proceed. */
final class CapturingEncoder implements AudioEncoderInterface
{
    public string $rawBytes = '';
    public int $sampleRate = 0;
    public ?TtsOutputFormat $format = null;

    public function assertReady(TtsOutputFormat $format): void {}

    public function encode(string $rawPath, string $destinationPath, int $sampleRate, TtsOutputFormat $format): void
    {
        $this->rawBytes = (string) file_get_contents($rawPath);
        $this->sampleRate = $sampleRate;
        $this->format = $format;

        file_put_contents($destinationPath, str_repeat("\xFF\xFB", 128));
    }
}
