<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Domain\AudioTranscriptionException;
use App\Tests\Support\AudioToTextSettingsFactory;
use PHPUnit\Framework\TestCase;

/**
 * The upload duration cap, at its edges.
 *
 * The number lives in exactly one operational place — `AUDIO_TRANSCRIPTION_MAX_DURATION` — and reaches
 * this rule through `Environment.php → params.php → DI → AudioToTextSettings`. These tests drive the
 * settings object the same way the container builds it, so a limit that stopped flowing through would
 * fail here rather than in production.
 */
final class TranscriptionDurationLimitTest extends TestCase
{
    private const FIVE_MINUTES = 300;

    /**
     * @return iterable<string, array{0: float, 1: bool}>
     */
    public static function boundary(): iterable
    {
        yield '299 seconds' => [299.0, true];
        yield '300 seconds exactly' => [300.0, true];
        yield 'a fraction under the cap' => [299.99, true];
        yield 'a fraction over the cap' => [300.01, false];
        yield '301 seconds' => [301.0, false];
        yield 'far too long' => [900.0, false];
    }

    /**
     * @dataProvider boundary
     */
    public function testTheCapIsInclusive(float $seconds, bool $expected): void
    {
        $settings = AudioToTextSettingsFactory::create(maxDurationSeconds: self::FIVE_MINUTES);

        self::assertSame($expected, $settings->transcription->allowsDuration($seconds));
    }

    /** The cap is whatever the setting says, not a number compiled into the rule. */
    public function testTheRuleFollowsTheConfiguredValueRatherThanAConstant(): void
    {
        $twoMinutes = AudioToTextSettingsFactory::create(maxDurationSeconds: 120);
        $tenMinutes = AudioToTextSettingsFactory::create(maxDurationSeconds: 600);

        self::assertFalse($twoMinutes->transcription->allowsDuration(200.0));
        self::assertTrue($tenMinutes->transcription->allowsDuration(200.0));

        // The old limit must not survive anywhere as an assumption: at the deployed setting, a
        // recording that the previous 120-second cap rejected is now accepted.
        $deployed = AudioToTextSettingsFactory::create(maxDurationSeconds: self::FIVE_MINUTES);
        self::assertTrue($deployed->transcription->allowsDuration(121.0));
    }

    /**
     * @return iterable<string, array{0: int, 1: string}>
     */
    public static function labels(): iterable
    {
        yield 'five minutes' => [300, '5 minutes'];
        yield 'two minutes' => [120, '2 minutes'];
        yield 'ten minutes' => [600, '10 minutes'];
        yield 'one minute' => [60, '1 minute'];
        yield 'under a minute' => [45, '45 seconds'];
        yield 'not a whole number of minutes' => [270, '4 minutes 30 seconds'];
    }

    /**
     * The displayed limit is derived, so raising the setting rewords the page and the rejection message
     * with nothing else to edit.
     *
     * @dataProvider labels
     */
    public function testTheDisplayedLimitIsDerivedFromTheSetting(int $seconds, string $expected): void
    {
        $settings = AudioToTextSettingsFactory::create(maxDurationSeconds: $seconds);

        self::assertSame($expected, $settings->transcription->maxDurationLabel());
    }

    /** What the person who uploaded a six-minute file actually reads. */
    public function testTheRejectionMessageStatesTheLimitInMinutes(): void
    {
        $settings = AudioToTextSettingsFactory::create(maxDurationSeconds: self::FIVE_MINUTES);

        $exception = AudioTranscriptionException::tooLong(
            361.0,
            $settings->transcription->maxDurationSeconds,
            $settings->transcription->maxDurationLabel(),
        );

        self::assertSame(
            'That recording is 6 minutes 1 second long. '
            . 'The maximum allowed duration is 5 minutes — please try a shorter one.',
            $exception->getMessage(),
        );
        // The log still gets the exact seconds; only the reader gets minutes.
        self::assertStringContainsString('361.00s exceeds the 300s limit', $exception->technicalDetail());
    }

    /**
     * Uncompressed PCM sizes for a full-length recording in each supported mono format.
     *
     * `sample rate x 2 bytes x 300 seconds`. These are the shapes that decide whether the duration cap
     * or the byte cap is the one an administrator actually runs into.
     *
     * @return iterable<string, array{0: int}>
     */
    public static function fiveMinuteRecordingSizes(): iterable
    {
        yield '8 kHz mono WAV (phone call)' => [8000 * 2 * self::FIVE_MINUTES];
        yield '16 kHz mono WAV' => [16000 * 2 * self::FIVE_MINUTES];
        yield '44.1 kHz mono WAV' => [44100 * 2 * self::FIVE_MINUTES];
        yield '48 kHz mono WAV' => [48000 * 2 * self::FIVE_MINUTES];
        yield 'MP3 at 320 kbps' => [(320 * 1000 / 8) * self::FIVE_MINUTES];
    }

    /**
     * The point of raising the byte cap: the duration limit should be the limit people meet.
     *
     * A five-minute recording that is rejected for its size teaches nothing about the actual rule, and
     * at the old 15 MB cap every WAV above 24 kHz was rejected that way.
     *
     * @dataProvider fiveMinuteRecordingSizes
     */
    public function testAFullLengthRecordingFitsInsideTheUploadCap(int $bytes): void
    {
        $settings = AudioToTextSettingsFactory::create();

        self::assertGreaterThanOrEqual(
            $bytes,
            $settings->transcription->maxUploadBytes,
            'A supported 5-minute recording would be rejected for its size before its duration.',
        );
    }

    /**
     * The cap cannot simply be raised until everything fits: nginx rejects a larger body with a 413
     * before PHP is reached, so the setting has to stay under `client_max_body_size`.
     */
    public function testTheUploadCapStaysBelowTheWebServerLimit(): void
    {
        $settings = AudioToTextSettingsFactory::create();

        self::assertLessThanOrEqual(
            32 * 1024 * 1024,
            $settings->transcription->maxUploadBytes,
            'Above nginx client_max_body_size the upload fails at the web server, not in validation.',
        );
        self::assertSame('30 MB', $settings->transcription->maxUploadLabel());
    }

    /**
     * The timeout has to scale with the duration cap, and the settings object refuses the combination
     * rather than letting every long recording die on the clock.
     */
    public function testAFiveMinuteCapRequiresATimeoutThatCanActuallyReachIt(): void
    {
        $tooLow = AudioToTextSettingsFactory::create(
            maxDurationSeconds: self::FIVE_MINUTES,
            timeoutSeconds: 300,
        );

        self::assertNotSame([], $tooLow->problems());

        $deployed = AudioToTextSettingsFactory::create(
            maxDurationSeconds: self::FIVE_MINUTES,
            timeoutSeconds: 600,
        );

        foreach ($deployed->problems() as $problem) {
            self::assertStringNotContainsString('AUDIO_TRANSCRIPTION_TIMEOUT', $problem);
        }
    }
}
