<?php

declare(strict_types=1);

namespace App\Reports\Domain;

use function max;
use function round;

/**
 * The headline figures for the selected range, all derived from agent conversations only.
 *
 * Two definitions carry the weight of the whole report:
 * - `$averageRating` averages **numeric scores only**. A dismissal stores no score and is never an implicit
 *   zero, so declining to rate can never drag the average down.
 * - `$unratedAnswers` counts answers that exist but carry no score, dismissals included. A question with no
 *   active answer is counted in `$unansweredQuestions` instead — it is not an unrated answer.
 */
final readonly class ChatReportSummary
{
    public function __construct(
        public int $activeAgents = 0,
        public int $questions = 0,
        public int $answers = 0,
        public int $unansweredQuestions = 0,
        public int $ratedAnswers = 0,
        public int $unratedAnswers = 0,
        public ?float $averageRating = null,
        public int $lowRatings = 0,
        public int $comments = 0,
        public int $storeQuestions = 0,
        public int $ruleQuestions = 0,
        public int $groundedAnswers = 0,
        public int $fallbackAnswers = 0,
        public int $sessions = 0,
        public int $chatSeconds = 0,
        public ?float $averageResponseSeconds = null,
    ) {}

    /** Rated answers as a share of answers that could have been rated. */
    public function ratingCoveragePercent(): ?float
    {
        return $this->answers === 0
            ? null
            : round($this->ratedAnswers / max(1, $this->answers) * 100, 1);
    }

    public function fallbackPercent(): ?float
    {
        return $this->answers === 0
            ? null
            : round($this->fallbackAnswers / max(1, $this->answers) * 100, 1);
    }

}
