<?php

declare(strict_types=1);

namespace App\AudioToText\Infrastructure\Diarization;

use App\AudioToText\Application\AudioToTextSettings;
use App\AudioToText\Domain\AudioTranscriptionException;
use App\AudioToText\Domain\Speaker\SpeakerDiarizerInterface;
use App\AudioToText\Domain\Speaker\SpeakerSegment;
use App\AudioToText\Infrastructure\Process\ProcessRunner;
use JsonException;

use function count;
use function is_array;
use function is_executable;
use function is_file;
use function is_int;
use function is_numeric;
use function is_readable;
use function json_decode;
use function sprintf;
use function trim;
use function usort;

use const JSON_THROW_ON_ERROR;

/**
 * Local speaker diarization via sherpa-onnx, driven by a small Python script in this directory.
 *
 * Why an external process rather than a PHP implementation: diarization is a neural pipeline —
 * segmentation model, speaker embeddings, clustering — and there is no PHP runtime for ONNX. The
 * script is invoked through the same {@see ProcessRunner} as ffmpeg and whisper, so it inherits argv
 * safety, the wall-clock timeout, bounded output capture and the single-thread environment.
 *
 * Why sherpa-onnx specifically. The installed whisper.cpp 1.9.3 offers `-di`, which splits stereo
 * channels and is useless for a mono telephone recording, and `-tdrz`, which needs an English-only
 * model and marks turn *boundaries* without ever establishing which turns belong to the same person.
 * Neither is speaker diarization. sherpa-onnx runs a pyannote segmentation model plus a speaker
 * embedding model entirely offline, is Apache-2.0, and is language-independent — which matters when the
 * recordings switch between English and Spanish mid-sentence.
 *
 * Everything stays on this machine. No audio is uploaded anywhere.
 */
final readonly class SherpaOnnxSpeakerDiarizer implements SpeakerDiarizerInterface
{
    private const SCRIPT = __DIR__ . '/diarize.py';

    public function __construct(
        private AudioToTextSettings $settings,
        private ProcessRunner $processes,
    ) {}

    public function isAvailable(): bool
    {
        return $this->settings->diarization->enabled
            && is_file($this->settings->diarization->binary)
            && is_executable($this->settings->diarization->binary)
            && is_file(self::SCRIPT)
            && is_readable($this->settings->diarization->segmentationModel)
            && is_readable($this->settings->diarization->embeddingModel);
    }

    public function method(): string
    {
        return 'sherpa-onnx';
    }

    /**
     * @return list<SpeakerSegment>
     */
    public function diarize(string $wavPath): array
    {
        if (!is_file($this->settings->diarization->binary) || !is_executable($this->settings->diarization->binary)) {
            throw AudioTranscriptionException::temporaryDirectoryNotWritable(
                $this->settings->diarization->binary,
                'the diarization interpreter is not executable',
            );
        }

        foreach ([$this->settings->diarization->segmentationModel, $this->settings->diarization->embeddingModel] as $model) {
            if (!is_readable($model)) {
                throw AudioTranscriptionException::modelMissing($model);
            }
        }

        $result = $this->processes->run([
            $this->settings->diarization->binary,
            self::SCRIPT,
            '--audio', $wavPath,
            '--segmentation-model', $this->settings->diarization->segmentationModel,
            '--embedding-model', $this->settings->diarization->embeddingModel,
            '--max-speakers', (string) $this->settings->diarization->maxSpeakers,
            // Explicit, not inherited from a default. sherpa-onnx already defaults both ONNX Runtime
            // thread pools to one, but a default is not a contract, and a future release widening it
            // would quietly hand this job eight cores. The number is the module's one CPU budget.
            '--num-threads', (string) $this->settings->transcription->threads,
        ], $this->settings->diarization->timeoutSeconds);

        if ($result->timedOut) {
            throw AudioTranscriptionException::transcriptionTimedOut($this->settings->diarization->timeoutSeconds);
        }

        if (!$result->isSuccessful()) {
            throw AudioTranscriptionException::transcriptionFailed('diarization: ' . $result->diagnostics());
        }

        return $this->parse($result->stdout);
    }

    /**
     * @return list<SpeakerSegment>
     */
    private function parse(string $stdout): array
    {
        $raw = trim($stdout);
        if ($raw === '') {
            throw AudioTranscriptionException::transcriptionFailed('diarization produced no output');
        }

        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw AudioTranscriptionException::transcriptionFailed(
                'diarization output was not valid JSON: ' . $e->getMessage(),
            );
        }

        $rows = is_array($decoded) ? ($decoded['segments'] ?? null) : null;

        if (!is_array($rows)) {
            throw AudioTranscriptionException::transcriptionFailed('diarization output had no segments array');
        }

        $segments = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $start = $row['start'] ?? null;
            $end = $row['end'] ?? null;
            $speaker = $row['speaker'] ?? null;

            if (!is_numeric($start) || !is_numeric($end) || !is_int($speaker)) {
                continue;
            }

            $startMs = (int) round((float) $start * 1000);
            $endMs = (int) round((float) $end * 1000);

            if ($endMs <= $startMs) {
                continue;
            }

            $segments[] = new SpeakerSegment($startMs, $endMs, SpeakerSegment::labelFor($speaker));
        }

        if ($segments === []) {
            throw AudioTranscriptionException::transcriptionFailed(
                sprintf('diarization returned no usable segments from %d rows', count($rows)),
            );
        }

        usort($segments, static fn(SpeakerSegment $a, SpeakerSegment $b): int => $a->startMs <=> $b->startMs);

        return $segments;
    }
}
