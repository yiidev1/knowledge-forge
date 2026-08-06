<?php

declare(strict_types=1);

namespace App\Chat\Application;

use App\Ai\Contract\Dto\GroundedAnswerResult;
use App\Chat\Application\Grounding\GroundingOutcome;

/**
 * One produced-but-not-yet-persisted answer for a single knowledge base: the raw provider result plus the
 * verified grounding outcome. The orchestrator decides which attempt (store, common, or the store's fallback)
 * to persist — exactly one is ever saved.
 */
final readonly class AnswerAttempt
{
    public function __construct(
        public GroundedAnswerResult $result,
        public GroundingOutcome $outcome,
    ) {}
}
