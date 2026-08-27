<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Speaker;

use App\AudioToText\Domain\SpeakerSeparationStatus;

use function json_encode;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * The complete result of the speaker-separation stage.
 *
 * Every unsuccessful path is still a value, never an exception: this stage runs after the transcript is
 * already committed, so its job is to report an outcome the caller can store, not to unwind anything.
 *
 * When the status is anything other than COMPLETED, `agentText` and `customerText` are null. That is the
 * deliberate choice behind the whole design — a half-confident split presented as fact is worse than an
 * honest "needs review", because nobody re-checks a column that looks finished.
 */
final readonly class SpeakerSeparatedTranscript
{
    /**
     * @param list<SpeakerUtterance> $utterances chronological; retained even for a NEEDS_REVIEW result
     *                                           so an inconclusive mapping can still be inspected
     * @param string|null            $reason     technical detail for the log, never for the browser
     */
    private function __construct(
        public SpeakerSeparationStatus $status,
        public ?string $agentText,
        public ?string $customerText,
        public array $utterances,
        public ?float $confidence,
        public string $method,
        public ?string $reason,
    ) {}

    /**
     * @param list<SpeakerUtterance> $utterances
     */
    public static function completed(
        string $agentText,
        string $customerText,
        array $utterances,
        float $confidence,
        string $method,
    ): self {
        return new self(
            SpeakerSeparationStatus::COMPLETED,
            $agentText,
            $customerText,
            $utterances,
            $confidence,
            $method,
            null,
        );
    }

    /**
     * Ran to completion, but the result is not safe to publish. The utterances are kept: an operator
     * looking at why a call was flagged needs to see what the diarizer actually produced.
     *
     * @param list<SpeakerUtterance> $utterances
     */
    public static function needsReview(
        array $utterances,
        ?float $confidence,
        string $method,
        string $reason,
    ): self {
        return new self(
            SpeakerSeparationStatus::NEEDS_REVIEW,
            null,
            null,
            $utterances,
            $confidence,
            $method,
            $reason,
        );
    }

    public static function failed(string $method, string $reason): self
    {
        return new self(SpeakerSeparationStatus::FAILED, null, null, [], null, $method, $reason);
    }

    public static function notSupported(string $reason): self
    {
        return new self(SpeakerSeparationStatus::NOT_SUPPORTED, null, null, [], null, 'none', $reason);
    }

    /**
     * Segments as they are stored. Null when there is nothing to audit, so an unattempted stage does not
     * leave an empty array behind that reads as "we looked and found no speech".
     */
    public function segmentsJson(): ?string
    {
        if ($this->utterances === []) {
            return null;
        }

        $rows = [];
        foreach ($this->utterances as $utterance) {
            $rows[] = $utterance->toArray();
        }

        // Substituting invalid sequences rather than throwing: a JSON encoder failure must not be able to
        // fail a job whose transcript is already safely stored.
        $encoded = json_encode(
            $rows,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return $encoded === false ? null : $encoded;
    }
}
