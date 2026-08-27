<?php

declare(strict_types=1);

namespace App\AudioToText\Infrastructure;

use App\AudioToText\Application\AudioToTextSettings;
use App\AudioToText\Domain\AudioTranscriptionException;
use App\AudioToText\Infrastructure\Process\ProcessRunner;

use function is_executable;
use function is_file;
use function is_numeric;
use function sprintf;
use function trim;

/**
 * Reads a recording's duration with ffprobe.
 *
 * Header-only: ffprobe is asked for one field and never decodes the audio, so this costs milliseconds
 * even on a file at the size limit. That is what makes it affordable inside the upload request, which
 * is where the duration check has to happen — the length limit exists to stop a long recording being
 * queued at all, and by the time the worker sees it the decision has already been made.
 */
final readonly class AudioDurationProbe
{
    private const PROBE_TIMEOUT_SECONDS = 20;

    public function __construct(
        private AudioToTextSettings $settings,
        private ProcessRunner $processes,
    ) {}

    public function seconds(string $path): float
    {
        $binary = $this->settings->transcription->ffprobeBinary;

        if (!is_file($binary) || !is_executable($binary)) {
            throw AudioTranscriptionException::ffprobeMissing($binary);
        }

        if (!is_file($path)) {
            throw AudioTranscriptionException::uploadUnreadable(
                sprintf('ffprobe was given a path that is not a file: "%s"', $path),
            );
        }

        $result = $this->processes->run([
            $binary,
            '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $path,
        ], self::PROBE_TIMEOUT_SECONDS);

        if ($result->timedOut) {
            throw AudioTranscriptionException::durationUnknown('ffprobe timed out');
        }

        if (!$result->isSuccessful()) {
            throw AudioTranscriptionException::durationUnknown('ffprobe failed: ' . $result->diagnostics());
        }

        $value = trim($result->stdout);

        // "N/A" is ffprobe's answer for a container it opened but could not measure — a truncated file,
        // or something that is not really audio. Either way the length limit cannot be enforced, and an
        // unenforceable limit is not one to wave through.
        if ($value === '' || !is_numeric($value)) {
            throw AudioTranscriptionException::durationUnknown(
                sprintf('ffprobe reported a non-numeric duration: "%s"', $value),
            );
        }

        return (float) $value;
    }
}
