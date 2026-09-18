<?php

declare(strict_types=1);

namespace App\AudioToText\Infrastructure\Tts;

use App\AudioToText\Application\AudioToTextSettings;
use App\AudioToText\Domain\Tts\AudioEncoderInterface;
use App\AudioToText\Domain\Tts\TtsException;
use App\AudioToText\Domain\Tts\TtsOutputFormat;
use App\AudioToText\Infrastructure\Process\ProcessRunner;

use function array_merge;
use function filesize;
use function is_executable;
use function is_file;
use function sprintf;
use function str_contains;

/**
 * The one ffmpeg invocation in this feature.
 *
 * Follows {@see \App\AudioToText\Infrastructure\Transcription\AudioNormalizer} closely, because that
 * class already encodes what this project has learned about running ffmpeg: an argv array through
 * {@see ProcessRunner} so there is no shell to quote against, `-nostdin` so a stalled process cannot sit
 * waiting on a terminal that is not there, `-threads 1` so the CPU budget stays the one core the whole
 * pipeline is designed around, and a post-condition that checks the output is non-empty **as well as**
 * checking the exit code — because ffmpeg does occasionally exit zero having written nothing.
 *
 * The input is raw PCM with no header, so its rate and layout are declared on the command line rather
 * than detected. That is the point of having joined the chunks as bytes: there is no container metadata
 * anywhere in the pipeline that could be wrong, and nothing to reconcile between pieces.
 */
final class FfmpegPcmEncoder implements AudioEncoderInterface
{
    /**
     * A valid MP3 frame is comfortably over a hundred bytes and a WAV header alone is 44, so anything
     * under this is a truncated write rather than a very short recording.
     */
    private const MINIMUM_PLAUSIBLE_BYTES = 128;

    /** Listing encoders is a process launch; the answer cannot change while this process is alive. */
    private ?string $encoderList = null;

    public function __construct(
        private readonly AudioToTextSettings $settings,
        private readonly ProcessRunner $processes,
    ) {}

    public function assertReady(TtsOutputFormat $format): void
    {
        $binary = $this->settings->transcription->ffmpegBinary;

        if (!is_file($binary) || !is_executable($binary)) {
            throw TtsException::encoderMissing(sprintf('ffmpeg at "%s"', $binary));
        }

        $encoder = $format->requiredEncoder();

        if (!str_contains($this->encoders($binary), $encoder)) {
            throw TtsException::encoderMissing($encoder);
        }
    }

    public function encode(
        string $rawPath,
        string $destinationPath,
        int $sampleRate,
        TtsOutputFormat $format,
    ): void {
        if (!is_file($rawPath) || filesize($rawPath) === 0) {
            throw TtsException::encodeFailed('there was no audio to encode');
        }

        $timeout = $this->settings->tts->timeoutSeconds;

        $result = $this->processes->run(
            array_merge(
                [
                    $this->settings->transcription->ffmpegBinary,
                    '-nostdin',
                    '-hide_banner',
                    '-loglevel',
                    'error',
                    '-y',
                    '-threads',
                    '1',
                    // The input is headerless, so its format is asserted rather than sniffed. These three
                    // must match what the synthesizer asked Deepgram for, or the result plays at the
                    // wrong speed — which is why both sides read the same setting.
                    '-f',
                    's16le',
                    '-ar',
                    (string) $sampleRate,
                    '-ac',
                    '1',
                    '-i',
                    $rawPath,
                ],
                $format->encodeArguments(),
                [$destinationPath],
            ),
            $timeout,
        );

        if ($result->timedOut) {
            throw TtsException::encodeTimedOut($timeout);
        }

        $size = is_file($destinationPath) ? filesize($destinationPath) : false;

        // Exit code and size, not one or the other. ffmpeg has been observed to exit zero having written
        // nothing, and a zero-byte file that the row calls READY is worse than an error: it presents as
        // working audio and plays silence.
        if (!$result->isSuccessful() || $size === false || $size < self::MINIMUM_PLAUSIBLE_BYTES) {
            throw TtsException::encodeFailed($result->diagnostics());
        }
    }

    private function encoders(string $binary): string
    {
        if ($this->encoderList !== null) {
            return $this->encoderList;
        }

        // 15 seconds is generous for printing a static list, and bounded so a wedged binary cannot hold
        // the worker's whole tick.
        $result = $this->processes->run([$binary, '-hide_banner', '-loglevel', 'error', '-encoders'], 15);

        return $this->encoderList = $result->stdout;
    }
}
