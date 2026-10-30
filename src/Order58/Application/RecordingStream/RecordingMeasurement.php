<?php

declare(strict_types=1);

namespace App\Order58\Application\RecordingStream;

/**
 * How long a recording is, learned from its size alone — without fetching the audio.
 *
 * ## Why a size can answer a duration at all
 *
 * The provider's recordings are uncompressed PCM WAV: a fixed number of bytes per second of audio, so
 * `seconds = (bytes - header) / byteRate` is exact rather than an estimate. Every recording measured
 * from this provider is 8 kHz, 16-bit, mono — 16,000 bytes a second — and the eight recordings on store
 * 1731's page were checked against the durations the page already displayed: **all eight matched to the
 * second**.
 *
 * That is why this is worth doing. The page previously learned each duration by asking for the first
 * 4 KB of the file, and the provider — which does not honour `Range` — answered every one of those
 * requests with the **whole recording**. Eight labels cost 37.4 MB and about twelve seconds of provider
 * time. A `HEAD` answers the same question having transferred **zero bytes**.
 *
 * ## It reports its own uncertainty
 *
 * `byteRate` is a property of the provider's recorder, not a fact this application can see in a `HEAD`
 * response. So a measurement is only {@see isUsable()} when the arithmetic lands somewhere a telephone
 * call could plausibly be. A file in some other format produces a duration this refuses to believe, the
 * caller is told nothing was learned, and the page falls back to reading the real header — the slow path
 * that was the only path before this existed. Being wrong is therefore not a failure mode: being
 * *unsure* is, and it costs only what the page already cost.
 */
final readonly class RecordingMeasurement
{
    /**
     * Bytes per second of audio for the provider's format: 8 kHz × 16-bit × 1 channel.
     *
     * A constant rather than a setting. A second deployment does not get to hold a different opinion
     * about what the provider records — if the provider ever changes format, the guard below starts
     * refusing measurements and the page quietly returns to reading headers, which is the behaviour
     * that was correct before this class existed.
     */
    public const BYTES_PER_SECOND = 16000;

    /**
     * The canonical WAV header. Real files often carry extra chunks and are therefore slightly longer,
     * which makes a duration computed from this a hair **long** rather than short — under a tenth of a
     * second on these recordings, and invisible once rounded to whole seconds.
     */
    private const HEADER_BYTES = 44;

    /** Shorter than this is not a call; it is a truncated file or an error page with a length. */
    private const MIN_SECONDS = 0.5;

    /** Longer than this is not a call either. Four hours of 8 kHz mono is about 230 MB. */
    private const MAX_SECONDS = 14400.0;

    private function __construct(
        public ?float $seconds,
        public ?int $bytes,
        public ?RecordingFetchFailure $failure,
    ) {}

    /**
     * A size the provider reported, turned into a duration if the arithmetic is believable.
     *
     * A size that does not produce a plausible duration is **not** an error: it is a recording this
     * shortcut cannot speak for, and {@see isUsable()} returns false so the caller takes the slow path.
     */
    public static function fromBytes(int $bytes): self
    {
        if ($bytes <= self::HEADER_BYTES) {
            return new self(null, $bytes, null);
        }

        $seconds = ($bytes - self::HEADER_BYTES) / self::BYTES_PER_SECOND;

        return $seconds < self::MIN_SECONDS || $seconds > self::MAX_SECONDS
            ? new self(null, $bytes, null)
            : new self($seconds, $bytes, null);
    }

    public static function failed(RecordingFetchFailure $failure): self
    {
        return new self(null, null, $failure);
    }

    /** Whether a duration was learned. False means "ask the header", never "there is no recording". */
    public function isUsable(): bool
    {
        return $this->seconds !== null;
    }
}
