<?php

declare(strict_types=1);

namespace App\Reports\Domain;

use DateTimeImmutable;

use function round;

/**
 * One agent's activity across the selected range.
 *
 * `$agentName`/`$agentUsername` are nullable because the join to the Order58 agent mirror is a LEFT JOIN by
 * necessity — there is no foreign key, and an agent can authenticate and chat before the agents sync has
 * ever run. A missing mirror row must not drop the agent's activity from the report.
 */
final readonly class AgentUsageRow
{
    public function __construct(
        public int $agentAdminId,
        public ?string $agentName,
        public ?string $agentUsername,
        public int $questions,
        public int $storeQuestions,
        public int $ruleQuestions,
        public int $answers,
        public int $ratedAnswers,
        public ?float $averageRating,
        public int $lowRatings,
        public int $comments,
        public int $sessions,
        public int $chatSeconds,
        public ?DateTimeImmutable $lastActivityAt,
        public ?DateTimeImmutable $lastLoginAt,
    ) {}

    public function agentLabel(): string
    {
        if ($this->agentName !== null && $this->agentName !== '') {
            return $this->agentName;
        }

        if ($this->agentUsername !== null && $this->agentUsername !== '') {
            return $this->agentUsername;
        }

        return 'Agent #' . $this->agentAdminId;
    }

    public function averageSessionSeconds(): int
    {
        return $this->sessions === 0 ? 0 : (int) round($this->chatSeconds / $this->sessions);
    }
}
