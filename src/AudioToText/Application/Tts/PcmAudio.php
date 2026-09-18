<?php

declare(strict_types=1);

namespace App\AudioToText\Application\Tts;

use function intdiv;
use function max;
use function str_repeat;

/**
 * The arithmetic of raw signed 16-bit mono PCM — the format every chunk comes back in.
 *
 * ## Why raw PCM rather than WAV
 *
 * Asking Deepgram for `container=wav` and stitching the results with ffmpeg's concat demuxer looks like
 * the obvious approach and carries three separate hazards, all of which disappear here:
 *
 *  - A WAV response is **streamed**, so the length fields in its RIFF header are placeholders rather
 *    than true sizes. A stream copy over such files produces output whose duration is wrong or unknown,
 *    and can truncate.
 *  - The concat demuxer requires bit-identical stream parameters across every input, and an ffmpeg
 *    `anullsrc` silence source defaults to **stereo** — so the silence would mismatch every speech chunk
 *    unless somebody remembered `cl=mono`.
 *  - It needs a list file on disk, `-safe 0`, and quoting rules for paths inside it.
 *
 * Raw PCM has no header to reconcile, no duration field to correct, and no requirement that the pieces
 * agree about anything except the sample rate they were all requested at. Joining becomes a byte
 * concatenation, silence becomes a run of zero bytes, and **the whole assembly is unit-testable without
 * ffmpeg being installed at all**. One ffmpeg call remains, at the very end, to put the result in a
 * container a browser will play.
 *
 * Signed 16-bit little-endian mono: two bytes per sample, and silence is the zero sample, so a run of
 * `\0` is genuine digital silence rather than an approximation of it.
 */
final class PcmAudio
{
    /** Two bytes per sample, one channel. */
    public const BYTES_PER_SAMPLE = 2;

    /**
     * A run of silence.
     *
     * Rounded down to a whole sample: a partial sample would shift every subsequent byte by one and turn
     * the rest of the file into noise, which is a spectacular way to discover that 16-bit audio has a
     * frame size.
     */
    public static function silence(int $milliseconds, int $sampleRate): string
    {
        if ($milliseconds <= 0 || $sampleRate <= 0) {
            return '';
        }

        $samples = intdiv($sampleRate * $milliseconds, 1000);

        return $samples <= 0 ? '' : str_repeat("\0", $samples * self::BYTES_PER_SAMPLE);
    }

    /**
     * How long a run of PCM lasts, for the page to report.
     *
     * Derived rather than probed: the bytes were written by this application at a known rate, so asking
     * ffprobe would spend a process to learn something already known exactly.
     */
    public static function durationSeconds(int $bytes, int $sampleRate): float
    {
        if ($sampleRate <= 0) {
            return 0.0;
        }

        return $bytes / (float) max(1, $sampleRate * self::BYTES_PER_SAMPLE);
    }
}
