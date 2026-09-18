<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Tts;

/**
 * Puts finished raw PCM into a container a browser will play.
 *
 * The only place ffmpeg is involved in this feature, and deliberately the last step rather than the
 * joining mechanism: chunks are concatenated as bytes, so there is exactly one process to run, one set
 * of arguments to get right, and one thing to substitute in a test.
 *
 * A port rather than a concrete class because {@see \App\Tests\Unit\AudioToText\WebTierCannotRunWhisperTest}
 * makes it a build failure for anything under a `Web/` directory to name a process runner, and because a
 * suite that had to have ffmpeg installed to test the generation logic would be a suite that gets
 * skipped.
 */
interface AudioEncoderInterface
{
    /**
     * Confirm this server can actually write the configured format.
     *
     * Called **before any work is claimed**, not at the point of use. `libmp3lame` is a build-time option
     * in ffmpeg rather than a given, and discovering it is missing after a call has been synthesised
     * means the provider has already been paid for audio that cannot be delivered.
     *
     * @throws TtsException naming the encoder that is missing
     */
    public function assertReady(TtsOutputFormat $format): void;

    /**
     * Encode raw signed 16-bit little-endian mono PCM into `$destinationPath`.
     *
     * The input has no header, so the rate and layout have to be declared rather than detected — which is
     * also why there is nothing here that could be detected wrongly.
     *
     * @throws TtsException on timeout, a non-zero exit, or output that is empty or implausibly small
     */
    public function encode(
        string $rawPath,
        string $destinationPath,
        int $sampleRate,
        TtsOutputFormat $format,
    ): void;
}
