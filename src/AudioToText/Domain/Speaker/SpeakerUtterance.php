<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Speaker;

use App\AudioToText\Domain\SpeakerRole;

/**
 * A run of consecutive tokens attributed to one speaker — the unit the UI and the role columns are
 * assembled from, and the unit stored in `speaker_segments` for audit.
 *
 * Both the neutral cluster and the mapped role are kept. Storing only the role would make a mapping
 * impossible to second-guess later; storing only the cluster would make the columns unexplainable.
 */
final readonly class SpeakerUtterance
{
    /**
     * @param float $confidence mean share of each token's duration that fell inside this speaker's
     *                          interval — 1.0 when every token sat cleanly inside one turn, lower where
     *                          speech overlapped or the diarizer's boundaries disagreed with whisper's.
     */
    public function __construct(
        public int $startMs,
        public int $endMs,
        public string $speaker,
        public SpeakerRole $role,
        public string $text,
        public float $confidence,
    ) {}

    /**
     * @return array{start_ms: int, end_ms: int, speaker: string, role: string, text: string, confidence: float}
     */
    public function toArray(): array
    {
        return [
            'start_ms' => $this->startMs,
            'end_ms' => $this->endMs,
            'speaker' => $this->speaker,
            'role' => $this->role->value,
            'text' => $this->text,
            'confidence' => round($this->confidence, 3),
        ];
    }

    public function withRole(SpeakerRole $role): self
    {
        return new self($this->startMs, $this->endMs, $this->speaker, $role, $this->text, $this->confidence);
    }
}
