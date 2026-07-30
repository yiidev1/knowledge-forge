<?php

declare(strict_types=1);

namespace App\Chat\Application\Grounding;

use App\Ai\Contract\Dto\GroundedAnswerResult;
use App\Chat\Application\ChatParams;

/**
 * The server-side guard that decides whether an answer may be shown, run on EVERY response before
 * anything is stored or rendered. It never trusts the model's word that it used the documents.
 *
 * The gate, in order (any failure ⇒ the exact fallback sentence, never a guess):
 *   1. the file_search tool must actually have been called;
 *   2. its status must be `completed`;
 *   3. it must have returned at least one result at or above the minimum score;
 *   4. citations are resolved upstream; unresolvable ids are already dropped;
 *   5. if citations are required and none resolved, the model's text is DISCARDED for the fallback —
 *      an uncited factual answer is never shown. If citations are not required, the text is shown but
 *      flagged as not grounded so the UI can warn.
 *
 * `retrieval_status` is recorded either way, so the decision is always auditable.
 */
final readonly class GroundingVerifier
{
    private const STATUS_NOT_CALLED = 'not_called';
    private const STATUS_COMPLETED = 'completed';

    public function __construct(
        private ChatParams $params,
    ) {}

    /**
     * @param list<\App\Chat\Domain\ResolvedCitation> $citations Already resolved and de-duplicated.
     */
    public function verify(GroundedAnswerResult $result, array $citations): GroundingOutcome
    {
        if (!$result->retrievalCalled) {
            return $this->fallback(self::STATUS_NOT_CALLED, GroundingOutcome::REASON_NOT_CALLED);
        }

        $status = $result->retrievalStatus;

        if ($status !== self::STATUS_COMPLETED) {
            return $this->fallback($status, GroundingOutcome::REASON_STATUS);
        }

        if ($result->retrievalResultCount < 1 || $result->topResultScore < $this->params->minCitationScore) {
            return $this->fallback($status, GroundingOutcome::REASON_NO_RESULTS);
        }

        if ($citations === []) {
            // Retrieval happened but nothing resolved to a document we can stand behind.
            //
            // Before blaming the documents, check whether the model simply ran out of output budget. A
            // truncated answer carries no annotations, which by citation count alone is indistinguishable
            // from an unsupported one. Both are still refused — an uncited answer is never shown — but
            // the user is told which of the two actually happened instead of a misleading message.
            if ($result->wasTruncated()) {
                return new GroundingOutcome(
                    $this->params->truncatedMessage,
                    false,
                    $status,
                    [],
                    GroundingOutcome::REASON_TRUNCATED,
                );
            }

            if ($this->params->requireCitations) {
                return $this->fallback($status, GroundingOutcome::REASON_NO_CITATIONS);
            }

            return new GroundingOutcome($result->text, false, $status, [], GroundingOutcome::REASON_NO_CITATIONS);
        }

        // Citations resolved, so the answer stands on real documents. It may still have been cut short;
        // that is recorded as a reason for the log without discarding an otherwise cited answer.
        return new GroundingOutcome(
            $result->text,
            true,
            $status,
            $citations,
            $result->wasTruncated() ? GroundingOutcome::REASON_TRUNCATED : null,
        );
    }

    private function fallback(string $retrievalStatus, string $reason): GroundingOutcome
    {
        return new GroundingOutcome($this->params->fallbackMessage, false, $retrievalStatus, [], $reason);
    }
}
