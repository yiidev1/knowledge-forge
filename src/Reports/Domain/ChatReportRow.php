<?php

declare(strict_types=1);

namespace App\Reports\Domain;

use DateTimeImmutable;

/**
 * One agent question and the answer that currently stands for it.
 *
 * `$answerId === null` means the question has no active answer — either the provider failed and the agent
 * has not retried, or an edit superseded the old answer and regeneration has not landed. The row still
 * appears: a question that never got answered is exactly the kind of thing this report exists to surface.
 *
 * Carries no OpenAI file id, vector store id, storage path or sync hash — the reader selects only the
 * columns below.
 */
final readonly class ChatReportRow
{
    public function __construct(
        public int $questionId,
        public DateTimeImmutable $askedAt,
        public int $agentAdminId,
        public ?string $agentName,
        public ?string $agentUsername,
        public ChatTypeFilter $chatType,
        public ?string $storeName,
        public string $question,
        public ?int $answerId,
        public ?string $answer,
        public bool $isGrounded,
        public ?int $score,
        public bool $dismissed,
        public ?string $comment,
        public ?int $responseSeconds,
        public int $citationCount,
        public bool $questionEdited,
    ) {}

    public function isAnswered(): bool
    {
        return $this->answerId !== null;
    }

    public function isRated(): bool
    {
        return $this->score !== null;
    }

    /** An answer exists but carries no score — a dismissal counts as unrated, never as a zero. */
    public function isUnrated(): bool
    {
        return $this->isAnswered() && $this->score === null;
    }

    public function agentLabel(): string
    {
        if ($this->agentName !== null && $this->agentName !== '') {
            return $this->agentName;
        }

        if ($this->agentUsername !== null && $this->agentUsername !== '') {
            return $this->agentUsername;
        }

        // The mirror has never seen this agent — show the id rather than an empty cell, so the row is
        // still traceable.
        return 'Agent #' . $this->agentAdminId;
    }
}
