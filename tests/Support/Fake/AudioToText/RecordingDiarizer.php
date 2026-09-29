<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\AudioToText;

use App\AudioToText\Domain\Speaker\SpeakerDiarizerInterface;
use App\AudioToText\Domain\Speaker\SpeakerSegment;

use function array_key_last;
use function count;

/**
 * A diarizer that answers from a script and remembers every question.
 *
 * Built for the re-clustering retry, where the outcome alone is not enough to tell two policies apart:
 * "diarize once" and "diarize twice and discard the second answer" end at the same status and differ
 * only in what they cost. {@see $asked} records the cluster count of each call in order, so a test can
 * assert the number of passes and what each one requested.
 *
 * Answers are consumed in order and the last one repeats, so a single-answer script behaves exactly like
 * the fixed fake it replaces.
 */
final class RecordingDiarizer implements SpeakerDiarizerInterface
{
    /**
     * One entry per call, in order: the cluster count asked for, or null for "the configured one".
     *
     * @var list<int|null>
     */
    public array $asked = [];

    /**
     * @param list<list<SpeakerSegment>> $answers in call order; the last one repeats
     */
    public function __construct(
        private readonly array $answers,
        private readonly bool $available = true,
    ) {}

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function method(): string
    {
        return 'sherpa-onnx';
    }

    /**
     * @return list<SpeakerSegment>
     */
    public function diarize(string $wavPath, ?int $maxSpeakers = null): array
    {
        $call = count($this->asked);
        $this->asked[] = $maxSpeakers;

        if ($this->answers === []) {
            return [];
        }

        return $this->answers[$call] ?? $this->answers[array_key_last($this->answers)];
    }

    public function calls(): int
    {
        return count($this->asked);
    }
}
