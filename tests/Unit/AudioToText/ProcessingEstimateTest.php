<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Domain\ProcessingEstimate;
use App\AudioToText\Domain\TranscriptionProvider;
use App\Tests\Support\TranscriptionJobFactory;
use PHPUnit\Framework\TestCase;

use function array_keys;

/**
 * How long this will take, said only as far as it can honestly be said.
 *
 * The estimate exists because a wait of a minute or more with no indication of length reads as a hang.
 * It is a sentence for somebody waiting, and the tests below are mostly about what it refuses to claim:
 * it is a range rather than a number, it includes the wait nobody can see, and it is **absent** rather
 * than guessed whenever the recording gives it nothing to work from.
 */
final class ProcessingEstimateTest extends TestCase
{
    /**
     * Deepgram is the fast path, and the estimate says so — in minutes, as a range.
     *
     * Measured at roughly a quarter of real time over completed jobs. A two-minute recording is
     * therefore well under a minute of actual work, and almost all of the estimate is the wait before a
     * worker picks it up — which is exactly why that wait is inside the number rather than left out of
     * it.
     */
    public function testACloudTranscriptionIsEstimatedFromItsMeasuredRatio(): void
    {
        $estimate = ProcessingEstimate::forJob(
            TranscriptionJobFactory::mixedRecording(provider: TranscriptionProvider::Deepgram, durationSeconds: 120.0),
        );

        self::assertNotNull($estimate);
        self::assertGreaterThanOrEqual(30, $estimate->lowSeconds);
        self::assertLessThan($estimate->highSeconds, $estimate->lowSeconds, 'A range, never a number.');
        self::assertLessThan(180, $estimate->highSeconds);
    }

    /** Whisper is local and slower than real time, and its estimate is correspondingly longer. */
    public function testALocalTranscriptionIsEstimatedHigher(): void
    {
        $deepgram = ProcessingEstimate::forJob(
            TranscriptionJobFactory::mixedRecording(provider: TranscriptionProvider::Deepgram, durationSeconds: 120.0),
        );
        $whisper = ProcessingEstimate::forJob(
            TranscriptionJobFactory::mixedRecording(provider: TranscriptionProvider::Whisper, durationSeconds: 120.0),
        );

        self::assertNotNull($deepgram);
        self::assertNotNull($whisper);
        self::assertGreaterThan(
            $deepgram->highSeconds,
            $whisper->highSeconds,
            'The engine the estimate was measured on is the one it describes.',
        );
    }

    /**
     * The wait before a worker starts is inside the estimate.
     *
     * Leaving it out would produce a figure that is honest about the part the user cannot see and wrong
     * about the part they can. A four-second clip does not finish in one second end to end.
     */
    public function testTheEstimateIncludesTheWaitBeforeAnythingStarts(): void
    {
        $estimate = ProcessingEstimate::forJob(
            TranscriptionJobFactory::mixedRecording(provider: TranscriptionProvider::Deepgram, durationSeconds: 4.0),
        );

        self::assertNotNull($estimate);
        self::assertGreaterThanOrEqual(30, $estimate->lowSeconds, 'A floor, because "a few seconds" would be wrong.');
        self::assertGreaterThanOrEqual(60, $estimate->highSeconds);
    }

    /**
     * Longer audio, longer estimate. Monotonic, or the number means nothing.
     */
    public function testALongerRecordingIsAlwaysEstimatedLonger(): void
    {
        $short = ProcessingEstimate::forJob(
            TranscriptionJobFactory::mixedRecording(provider: TranscriptionProvider::Whisper, durationSeconds: 60.0),
        );
        $long = ProcessingEstimate::forJob(
            TranscriptionJobFactory::mixedRecording(provider: TranscriptionProvider::Whisper, durationSeconds: 600.0),
        );

        self::assertNotNull($short);
        self::assertNotNull($long);
        self::assertGreaterThan($short->highSeconds, $long->highSeconds);
    }

    /**
     * No duration, no estimate — and the screen says "a few minutes" instead.
     *
     * A recording whose length was never probed offers nothing to multiply. Returning a figure derived
     * from a duration nobody measured would be the one thing this class exists to avoid.
     */
    public function testARecordingWithNoMeasuredLengthGetsNoEstimate(): void
    {
        foreach ([null, 0.0, -1.0] as $duration) {
            self::assertNull(ProcessingEstimate::forJob(
                TranscriptionJobFactory::mixedRecording(
                    provider: TranscriptionProvider::Deepgram,
                    durationSeconds: $duration,
                ),
            ));
        }
    }

    /** The two bounds are what the endpoint publishes, under the names the browser reads. */
    public function testItPublishesTwoBoundsAndNothingElse(): void
    {
        $estimate = ProcessingEstimate::forJob(
            TranscriptionJobFactory::mixedRecording(provider: TranscriptionProvider::Whisper, durationSeconds: 90.0),
        );

        self::assertNotNull($estimate);
        self::assertSame(['lowSeconds', 'highSeconds'], array_keys($estimate->toArray()));
    }
}
