<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Tts;

/**
 * The container the finished training audio is written in.
 *
 * Not the format Deepgram is asked for. Chunks always come back as raw signed 16-bit PCM, which is what
 * makes them safe to join with a byte concatenation; this is what the joined result is *encoded to*,
 * once, at the end.
 *
 * MP3 is the default for a reason a size measurement settles: linear16 at 24 kHz mono is 48 kB per
 * second — 384 kbps — and the shipped retention keeps recordings indefinitely. At 64 kbps MP3 is six
 * times smaller, measured at 1.5 MB against about 9 MB on a real 188-second rendition. Nobody is going
 * to master audio from a training file, so paying six times the disk for it would be a choice rather
 * than a default. WAV stays available for a deployment that wants it.
 */
enum TtsOutputFormat: string
{
    case Mp3 = 'mp3';
    case Wav = 'wav';

    /**
     * Decode a configured value, defaulting to MP3.
     *
     * Unlike most `fromStorage()` methods here this one does not return null: the value comes from
     * `.env`, where a typo should not be able to stop a worker starting when there is an obviously
     * correct thing to do instead. A wrong value is surfaced by {@see TtsSettings::warnings()} rather
     * than by refusing to run.
     */
    public static function fromConfig(string $value): self
    {
        return self::tryFrom(strtolower(trim($value))) ?? self::Mp3;
    }

    public function extension(): string
    {
        return $this->value;
    }

    /**
     * The exact Content-Type the file must be served with.
     *
     * `X-Content-Type-Options: nosniff` is set application-wide, so a browser will refuse to play audio
     * whose declared type is wrong rather than working it out from the bytes. This value is therefore
     * load-bearing, not cosmetic.
     */
    public function contentType(): string
    {
        return match ($this) {
            self::Mp3 => 'audio/mpeg',
            self::Wav => 'audio/wav',
        };
    }

    /**
     * The ffmpeg output arguments for this format, given raw PCM on the input side.
     *
     * 64 kbps mono is comfortably transparent for speech and roughly twenty times smaller than the PCM
     * it replaces. WAV re-encodes to `pcm_s16le` rather than copying, so the RIFF header the browser
     * reads is written by ffmpeg with the true length in it.
     *
     * @return list<string>
     */
    public function encodeArguments(): array
    {
        return match ($this) {
            self::Mp3 => ['-c:a', 'libmp3lame', '-b:a', '64k'],
            self::Wav => ['-c:a', 'pcm_s16le'],
        };
    }

    /**
     * The ffmpeg encoder this format needs present.
     *
     * Checked before any work is claimed. `libmp3lame` is a build-time option rather than a given, and
     * discovering it is missing after a call has been synthesised means the money is already spent.
     */
    public function requiredEncoder(): string
    {
        return match ($this) {
            self::Mp3 => 'libmp3lame',
            self::Wav => 'pcm_s16le',
        };
    }
}
