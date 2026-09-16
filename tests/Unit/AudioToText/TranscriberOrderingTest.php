<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Application\AudioToTextSettings;
use App\AudioToText\Application\TranscriberResolver;
use App\AudioToText\Domain\AudioTranscriptionException;
use App\AudioToText\Domain\Transcription\AudioTranscriptionResult;
use App\AudioToText\Domain\Transcription\TranscriptionEngineInterface;
use App\AudioToText\Domain\Transcription\TranscriptionRequest;
use App\AudioToText\Domain\TranscriptionProvider;
use App\AudioToText\Infrastructure\AudioTranscriber;
use App\AudioToText\Infrastructure\Process\ProcessRunner;
use App\AudioToText\Infrastructure\Transcription\AudioNormalizer;
use App\Tests\Support\AudioToTextSettingsFactory;
use PHPUnit\Framework\TestCase;

use function chmod;
use function explode;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function is_dir;
use function mkdir;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function trim;
use function unlink;

/**
 * The orchestrator's ordering, and the ffmpeg argv, asserted against a real `ProcessRunner`.
 *
 * `ProcessRunner` is `final readonly` and cannot be doubled — which turns out to be the better test
 * anyway: `ffmpegBinary` points at a small script that records the arguments it was given, so what is
 * asserted is the argv that actually reached `execve()`, not an argument array captured one call
 * earlier. The same script's *absence of output* is what proves ffmpeg never ran.
 *
 * **Nothing here runs a real ffmpeg, whisper or HTTP request.**
 */
final class TranscriberOrderingTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/a2t-ordering-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/work', 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
    }

    // ------------------------------------------------------------------ readiness precedes work

    /**
     * **The ordering assertion.** An unready engine fails before ffmpeg is started.
     *
     * A job queued for a provider this machine cannot run must cost nothing: no conversion, and no
     * workspace left behind. Checking readiness after normalisation would spend a minute of CPU on a
     * recording that was never going to be transcribed.
     */
    public function testAnUnreadyEngineFailsBeforeFfmpegRuns(): void
    {
        $transcriber = $this->transcriber($this->engine(TranscriptionProvider::Whisper, ready: false));

        try {
            $transcriber->transcribeFile($this->sourceFile(), TranscriptionProvider::Whisper);
            $this->fail('An unready engine must not transcribe.');
        } catch (AudioTranscriptionException $e) {
            $this->assertStringContainsString('not configured', $e->getMessage());
        }

        $this->assertFalse(file_exists($this->argvLog()), 'ffmpeg must not have been started.');
    }

    /** And no workspace is left behind for the orphan sweeper to find. */
    public function testAnUnreadyEngineCreatesNoWorkspace(): void
    {
        try {
            $this->transcriber($this->engine(TranscriptionProvider::Whisper, ready: false))
                ->transcribeFile($this->sourceFile(), TranscriptionProvider::Whisper);
        } catch (AudioTranscriptionException) {
            // Expected; the assertion is about what was left on disk.
        }

        $this->assertSame([], $this->workspaces(), 'No per-run directory may be created.');
    }

    /**
     * Whisper being broken must not stop a Deepgram job, which is the whole point of the split.
     *
     * Both engines are registered; only the selected one is consulted.
     */
    public function testABrokenWhisperDoesNotPreventADeepgramJob(): void
    {
        $transcriber = $this->transcriber(
            $this->engine(TranscriptionProvider::Whisper, ready: false),
            $this->engine(TranscriptionProvider::Deepgram, ready: true),
        );

        $result = $transcriber->transcribeFile($this->sourceFile(), TranscriptionProvider::Deepgram);

        $this->assertSame('transcribed by DEEPGRAM', $result->text);
    }

    public function testAnAbsentDeepgramDoesNotPreventAWhisperJob(): void
    {
        $transcriber = $this->transcriber(
            $this->engine(TranscriptionProvider::Whisper, ready: true),
            $this->engine(TranscriptionProvider::Deepgram, ready: false),
        );

        $this->assertSame(
            'transcribed by WHISPER',
            $transcriber->transcribeFile($this->sourceFile(), TranscriptionProvider::Whisper)->text,
        );
    }

    /** The provider the job carries is the one that runs — nothing re-reads a default. */
    public function testTheSelectedProviderIsTheOneThatRuns(): void
    {
        $transcriber = $this->transcriber(
            $this->engine(TranscriptionProvider::Whisper, ready: true),
            $this->engine(TranscriptionProvider::Deepgram, ready: true),
        );

        $this->assertSame(
            'transcribed by DEEPGRAM',
            $transcriber->transcribeFile($this->sourceFile(), TranscriptionProvider::Deepgram)->text,
        );
    }

    // ------------------------------------------------------------------ the shared ffmpeg step

    /**
     * The normalisation argv, byte for byte.
     *
     * Pinned because the pipeline depends on the exact output format: whisper.cpp will not resample,
     * the diarizer reads the same file, and `-threads 1` is what keeps the whole pipeline on one core.
     * A well-meaning edit here would be invisible until transcripts came back as silence.
     */
    public function testFfmpegIsGivenExactlyTheNormalisationArguments(): void
    {
        $transcriber = $this->transcriber($this->engine(TranscriptionProvider::Whisper, ready: true));
        $source = $this->sourceFile();

        $transcriber->transcribeFile($source, TranscriptionProvider::Whisper);

        $argv = explode("\n", trim((string) file_get_contents($this->argvLog())));

        // The workspace path is random, so the destination is checked by shape rather than by value.
        $destination = array_pop($argv);

        $this->assertSame([
            '-nostdin',
            '-hide_banner',
            '-loglevel', 'error',
            '-y',
            '-threads', '1',
            '-i', $source,
            '-ar', '16000',
            '-ac', '1',
            '-c:a', 'pcm_s16le',
        ], $argv);

        $this->assertStringEndsWith('/audio.wav', (string) $destination);
    }

    /** Both engines receive the same normalised file — that is what keeps the timelines aligned. */
    public function testEveryProviderReceivesTheSameNormalisedWav(): void
    {
        $seen = [];

        foreach ([TranscriptionProvider::Whisper, TranscriptionProvider::Deepgram] as $provider) {
            $engine = $this->engine($provider, ready: true, capture: $seen);
            $this->transcriber($engine)->transcribeFile($this->sourceFile(), $provider);
        }

        $this->assertCount(2, $seen);
        $this->assertStringEndsWith('/audio.wav', $seen[0]);
        $this->assertStringEndsWith('/audio.wav', $seen[1]);
    }

    /** The workspace is removed on the success path too, not only on failure. */
    public function testTheWorkspaceIsAlwaysRemoved(): void
    {
        $this->transcriber($this->engine(TranscriptionProvider::Whisper, ready: true))
            ->transcribeFile($this->sourceFile(), TranscriptionProvider::Whisper);

        $this->assertSame([], $this->workspaces());
    }

    /**
     * The callback that commits the transcript before diarization starts.
     *
     * If an engine ever returned without the orchestrator invoking this, `markTranscribed` would never
     * run and every job would complete with no transcript stored.
     */
    public function testTheWorkspaceCallbackRunsBeforeTheWorkspaceIsRemoved(): void
    {
        $wavSeenByCallback = null;

        $this->transcriber($this->engine(TranscriptionProvider::Whisper, ready: true))->transcribeFile(
            $this->sourceFile(),
            TranscriptionProvider::Whisper,
            null,
            function (string $wav, AudioTranscriptionResult $result) use (&$wavSeenByCallback): void {
                $wavSeenByCallback = $wav;
                $this->assertFileExists($wav, 'The WAV must still exist when diarization would read it.');
                $this->assertSame('transcribed by WHISPER', $result->text);
            },
        );

        $this->assertNotNull($wavSeenByCallback);
        $this->assertFileDoesNotExist((string) $wavSeenByCallback, 'and must be gone afterwards.');
    }

    public function testTheStagesAreReportedInOrder(): void
    {
        $stages = [];

        $this->transcriber($this->engine(TranscriptionProvider::Whisper, ready: true))->transcribeFile(
            $this->sourceFile(),
            TranscriptionProvider::Whisper,
            static function (string $stage) use (&$stages): void {
                $stages[] = $stage;
            },
        );

        $this->assertSame(['CONVERTING', 'TRANSCRIBING'], $stages);
    }

    // ------------------------------------------------------------------ helpers

    private function transcriber(TranscriptionEngineInterface ...$engines): AudioTranscriber
    {
        $settings = $this->settings();
        $runner = new ProcessRunner($settings);

        return new AudioTranscriber(
            $settings,
            TranscriberResolver::of(...$engines),
            new AudioNormalizer($settings, $runner),
        );
    }

    private function settings(): AudioToTextSettings
    {
        return AudioToTextSettingsFactory::create(
            ffmpegBinary: $this->fakeFfmpeg(),
            temporaryDirectory: $this->dir . '/work',
        );
    }

    /**
     * A script that records its own argv and writes the output file ffmpeg would have written.
     *
     * Writing bytes matters: the normaliser treats a zero-length output as a conversion failure, which
     * is a real guard and not one to bypass here.
     */
    private function fakeFfmpeg(): string
    {
        $path = $this->dir . '/fake-ffmpeg';

        if (!file_exists($path)) {
            file_put_contents($path, <<<SH
                #!/bin/sh
                for arg in "\$@"; do printf '%s\\n' "\$arg" >> '{$this->argvLog()}'; done
                # The last argument is ffmpeg's output path.
                for last in "\$@"; do :; done
                printf 'RIFF....WAVEfmt ' > "\$last"
                SH);
            chmod($path, 0o700);
        }

        return $path;
    }

    private function argvLog(): string
    {
        return $this->dir . '/argv.log';
    }

    private function sourceFile(): string
    {
        $path = $this->dir . '/source.mp3';
        file_put_contents($path, 'not really an mp3, and never decoded by this test');

        return $path;
    }

    /**
     * Per-run directories still sitting under the temporary root.
     *
     * @return list<string>
     */
    private function workspaces(): array
    {
        $base = $this->dir . '/work';

        if (!is_dir($base)) {
            return [];
        }

        $found = [];
        foreach ((array) scandir($base) as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_dir($base . '/' . $entry)) {
                $found[] = (string) $entry;
            }
        }

        return $found;
    }

    /**
     * @param list<string> $capture filled with each WAV path the engine was asked to recognise
     */
    private function engine(
        TranscriptionProvider $provider,
        bool $ready,
        array &$capture = [],
    ): TranscriptionEngineInterface {
        return new class ($provider, $ready, $capture) implements TranscriptionEngineInterface {
            /**
             * @param list<string> $capture
             */
            public function __construct(
                private readonly TranscriptionProvider $provider,
                private readonly bool $ready,
                private array &$capture,
            ) {}

            public function provider(): TranscriptionProvider
            {
                return $this->provider;
            }

            public function isAvailable(): bool
            {
                return $this->ready;
            }

            public function assertReady(): void
            {
                if (!$this->ready) {
                    throw AudioTranscriptionException::providerNotConfigured(
                        $this->provider->label(),
                        'deliberately unready in this test',
                    );
                }
            }

            public function recognise(TranscriptionRequest $request): AudioTranscriptionResult
            {
                $this->capture[] = $request->wavPath;

                return new AudioTranscriptionResult('transcribed by ' . $this->provider->value, null, []);
            }
        };
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach ((array) glob($path . '/*') as $entry) {
            $entry = (string) $entry;
            is_dir($entry) ? $this->removeTree($entry) : @unlink($entry);
        }

        @rmdir($path);
    }
}
