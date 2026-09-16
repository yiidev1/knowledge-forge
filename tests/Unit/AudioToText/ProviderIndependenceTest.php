<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Application\TranscriberResolver;
use App\AudioToText\Domain\AudioTranscriptionException;
use App\AudioToText\Domain\Transcription\AudioTranscriptionResult;
use App\AudioToText\Domain\Transcription\TranscriptionEngineInterface;
use App\AudioToText\Domain\Transcription\TranscriptionRequest;
use App\AudioToText\Domain\TranscriptionProvider;
use App\Tests\Support\AudioToTextSettingsFactory;
use PHPUnit\Framework\TestCase;

use function implode;

/**
 * **One provider's misconfiguration may not affect the other, and may not stop the worker.**
 *
 * This was a real architectural flaw, not a hypothetical: the whisper binary and model used to be
 * checked in `AudioToTextSettings::problems()`, which the worker consults at startup and exits
 * `DATAERR` on. A machine that had never installed whisper.cpp therefore could not drain a queue of
 * Deepgram jobs — the worker refused to start at all.
 *
 * The tests below pin the corrected arrangement from both directions, so a future refactor that moves
 * a provider check back into `problems()` fails here with the reason written down.
 */
final class ProviderIndependenceTest extends TestCase
{
    private const MISSING = '/nonexistent/definitely-not-here';

    // ------------------------------------------------------------------ the worker still starts

    /**
     * The load-bearing assertion. A non-empty `problems()` stops the worker before it claims anything.
     */
    public function testAMissingWhisperInstallDoesNotStopTheWorkerStarting(): void
    {
        $settings = AudioToTextSettingsFactory::create(
            whisperBinary: self::MISSING,
            whisperModel: self::MISSING,
        );

        $this->assertSame(
            [],
            $settings->problems(),
            'A missing Whisper install must fail Whisper jobs only, never the whole worker.',
        );
    }

    public function testAnAbsentDeepgramKeyDoesNotStopTheWorkerStarting(): void
    {
        $this->assertSame([], AudioToTextSettingsFactory::create(deepgramApiKey: '')->problems());
    }

    /** Neither provider configured is still not a worker-level problem. */
    public function testNeitherProviderBeingReadyStillLetsTheWorkerStart(): void
    {
        $settings = AudioToTextSettingsFactory::create(
            whisperBinary: self::MISSING,
            whisperModel: self::MISSING,
            deepgramApiKey: '',
        );

        $this->assertSame([], $settings->problems());
    }

    /** ffmpeg belongs to every provider — the diarizer consumes its output too — so it still blocks. */
    public function testAMissingFfmpegIsStillAWorkerLevelProblem(): void
    {
        $problems = AudioToTextSettingsFactory::create(ffmpegBinary: self::MISSING)->problems();

        $this->assertNotSame([], $problems);
        $this->assertStringContainsString('FFMPEG_BINARY', implode(' ', $problems));
    }

    public function testAMissingFfprobeIsStillAWorkerLevelProblem(): void
    {
        $problems = AudioToTextSettingsFactory::create(ffprobeBinary: self::MISSING)->problems();

        $this->assertStringContainsString('FFPROBE_BINARY', implode(' ', $problems));
    }

    // ------------------------------------------------------------------ but it is still visible

    /**
     * Not stopping the worker must not mean saying nothing: an operator sees the problem on every tick.
     */
    public function testAnUnreadyProviderIsNamedInTheStartupWarnings(): void
    {
        $warnings = implode(' ', AudioToTextSettingsFactory::create(
            whisperBinary: self::MISSING,
            whisperModel: self::MISSING,
        )->warnings());

        $this->assertStringContainsString('Whisper', $warnings);
        $this->assertStringContainsString('WHISPER_BINARY', $warnings);
        $this->assertStringContainsString('other providers are unaffected', $warnings);
    }

    public function testAnUnconfiguredDeepgramIsNamedInTheStartupWarnings(): void
    {
        $warnings = implode(' ', AudioToTextSettingsFactory::create(deepgramApiKey: '')->warnings());

        $this->assertStringContainsString('Deepgram', $warnings);
        $this->assertStringContainsString('DEEPGRAM_API_KEY', $warnings);
    }

    /** A ready provider says nothing. Warnings that always fire are warnings nobody reads. */
    public function testAReadyProviderProducesNoWarning(): void
    {
        $settings = AudioToTextSettingsFactory::create(
            whisperBinary: '/usr/bin/env',
            whisperModel: '/etc/hostname',
            deepgramApiKey: 'configured-key-value',
        );

        $this->assertSame([], $settings->providerProblems());
    }

    // ------------------------------------------------------------------ per-provider readiness

    public function testWhisperReadinessIgnoresDeepgramEntirely(): void
    {
        $settings = AudioToTextSettingsFactory::create(
            whisperBinary: '/usr/bin/env',
            whisperModel: '/etc/hostname',
            deepgramApiKey: '',
        );

        $this->assertTrue($settings->providerIsUsable(TranscriptionProvider::Whisper));
        $this->assertFalse($settings->providerIsUsable(TranscriptionProvider::Deepgram));
    }

    public function testDeepgramReadinessIgnoresWhisperEntirely(): void
    {
        $settings = AudioToTextSettingsFactory::create(
            whisperBinary: self::MISSING,
            whisperModel: self::MISSING,
            deepgramApiKey: 'configured-key-value',
        );

        $this->assertFalse($settings->providerIsUsable(TranscriptionProvider::Whisper));
        $this->assertTrue($settings->providerIsUsable(TranscriptionProvider::Deepgram));
    }

    /**
     * Deepgram's own configuration is checked beyond the key alone — a base URL that already carries a
     * query string would produce two `?` separators and a request Deepgram cannot parse.
     */
    public function testAMalformedDeepgramBaseUrlIsAReadinessProblem(): void
    {
        $withQuery = AudioToTextSettingsFactory::create(
            deepgramApiKey: 'configured-key-value',
            deepgramBaseUrl: 'https://api.deepgram.com/v1/listen?model=nova-3',
        );
        $this->assertFalse($withQuery->deepgram->isUsable());

        $relative = AudioToTextSettingsFactory::create(
            deepgramApiKey: 'configured-key-value',
            deepgramBaseUrl: '/v1/listen',
        );
        $this->assertFalse($relative->deepgram->isUsable());
    }

    /** The exactly-countable limit blocks; it is a configuration error, not a per-request surprise. */
    public function testTooManyKeytermsMakesDeepgramUnready(): void
    {
        $terms = [];
        for ($i = 0; $i <= 100; $i++) {
            $terms[] = 'term' . $i;
        }

        $settings = AudioToTextSettingsFactory::create(
            deepgramApiKey: 'configured-key-value',
            deepgramKeyterms: $terms,
        );

        $this->assertFalse($settings->deepgram->isUsable());
        $this->assertFalse($settings->providerIsUsable(TranscriptionProvider::Deepgram));
    }

    /**
     * **The asymmetry, at the settings level.** An over-budget estimate warns and stays usable.
     *
     * Deepgram is the authority on the token budget; refusing a configuration because a local word
     * count disagreed with it would reject requests Deepgram would have accepted.
     */
    public function testAnOverBudgetKeytermEstimateWarnsWithoutMakingDeepgramUnready(): void
    {
        $terms = [];
        for ($i = 0; $i < 60; $i++) {
            $terms[] = 'phrase' . $i . ' word word word word word word word word word';
        }

        $settings = AudioToTextSettingsFactory::create(
            deepgramApiKey: 'configured-key-value',
            deepgramKeyterms: $terms,
        );

        $this->assertTrue($settings->deepgram->isUsable(), 'The token estimate must never block.');
        $this->assertStringContainsString('estimate', implode(' ', $settings->deepgram->warnings()));
    }

    /** Keyterms configured against a model that cannot use them is worth saying, and nothing more. */
    public function testKeytermsOnANonNovaThreeModelWarnWithoutBlocking(): void
    {
        $settings = AudioToTextSettingsFactory::create(
            deepgramApiKey: 'configured-key-value',
            deepgramModel: 'nova-2',
            deepgramKeyterms: ['wonton'],
        );

        $this->assertTrue($settings->deepgram->isUsable());
        $this->assertStringContainsString('Nova-3', implode(' ', $settings->deepgram->warnings()));
    }

    // ------------------------------------------------------------------ one job fails, not the queue

    /**
     * An unready engine throws the exception the worker already knows how to turn into a FAILED job,
     * and the resolver keeps handing out the other engine unaffected.
     */
    public function testAnUnreadyEngineFailsItselfWhileTheOtherKeepsWorking(): void
    {
        $resolver = TranscriberResolver::of(
            $this->engine(TranscriptionProvider::Whisper, ready: false),
            $this->engine(TranscriptionProvider::Deepgram, ready: true),
        );

        $resolver->for(TranscriptionProvider::Deepgram)->assertReady();

        $this->assertSame([TranscriptionProvider::Deepgram], $resolver->available());

        $this->expectException(AudioTranscriptionException::class);
        $resolver->for(TranscriptionProvider::Whisper)->assertReady();
    }

    private function engine(TranscriptionProvider $provider, bool $ready): TranscriptionEngineInterface
    {
        return new class ($provider, $ready) implements TranscriptionEngineInterface {
            public function __construct(
                private readonly TranscriptionProvider $provider,
                private readonly bool $ready,
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
                    throw AudioTranscriptionException::providerNotConfigured($this->provider->label(), 'test');
                }
            }

            public function recognise(TranscriptionRequest $request): AudioTranscriptionResult
            {
                return new AudioTranscriptionResult('ok', null, []);
            }
        };
    }
}
