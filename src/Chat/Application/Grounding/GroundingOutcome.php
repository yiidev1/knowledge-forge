<?php

declare(strict_types=1);

namespace App\Chat\Application\Grounding;

use App\Chat\Domain\ResolvedCitation;

/**
 * The verified result of one answer: the text that will actually be shown and stored (the model's, or
 * the fallback), whether it counts as grounded, the retrieval status for the audit trail, and the
 * citations that survived resolution.
 */
final readonly class GroundingOutcome
{
    public const REASON_NOT_CALLED = 'retrieval_not_called';
    public const REASON_STATUS = 'retrieval_status_not_completed';
    public const REASON_NO_RESULTS = 'no_results_above_threshold';
    public const REASON_NO_CITATIONS = 'no_resolvable_citations';
    public const REASON_TRUNCATED = 'answer_truncated';

    /**
     * @param list<ResolvedCitation> $citations
     */
    public function __construct(
        public string $text,
        public bool $isGrounded,
        public string $retrievalStatus,
        public array $citations,
        /**
         * Machine-readable reason the answer was rejected, or null when it was accepted.
         *
         * The stored text alone cannot carry this: the model is instructed to emit the fallback sentence
         * itself when it finds nothing, so a rejected answer and a self-refused one are byte-identical
         * once stored. This is what makes the two distinguishable in the log.
         */
        public ?string $rejectionReason = null,
    ) {}
}
