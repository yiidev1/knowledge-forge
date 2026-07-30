<?php

declare(strict_types=1);

namespace App\Ai\Contract\Dto;

/**
 * A normalised answer from the provider, carrying everything the grounding check needs.
 *
 * The retrieval fields are the evidence the application uses to decide whether the answer is actually
 * grounded: whether the file-search tool ran, whether it completed, how many results it returned and
 * their best score. Citations are only those the provider genuinely reported — never invented.
 */
final readonly class GroundedAnswerResult
{
    public const STATUS_INCOMPLETE = 'incomplete';

    /**
     * @param list<RawCitation> $citations
     */
    public function __construct(
        public string $text,
        public bool $retrievalCalled,
        public string $retrievalStatus,
        public int $retrievalResultCount,
        public float $topResultScore,
        public array $citations,
        public TokenUsage $usage,
        public ?string $providerResponseId,
        public string $model,
        /** The provider's own completion status for the whole response, e.g. `completed`/`incomplete`. */
        public string $responseStatus = 'completed',
        /** Set when generation stopped early, e.g. `max_output_tokens`. */
        public ?string $incompleteReason = null,
        /** How many searches the model ran this turn, and how many of them completed. */
        public int $searchCallCount = 0,
        public int $completedSearchCallCount = 0,
    ) {}

    /**
     * The model was cut off before it finished writing the answer.
     *
     * Worth distinguishing: a truncated answer carries no citation annotations, which looks exactly like
     * an answer the documents could not support — two very different things to report to a user.
     */
    public function wasTruncated(): bool
    {
        return $this->responseStatus === self::STATUS_INCOMPLETE;
    }
}
