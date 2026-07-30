<?php

declare(strict_types=1);

namespace App\KnowledgeBase\Domain;

use DateTimeImmutable;

/**
 * An answering rule belonging to a knowledge base.
 *
 * Rules are plain text applied in priority order when building the chat prompt. They are entirely
 * provider-neutral — nothing here knows OpenAI exists — so they survive a change of AI provider
 * untouched. The immutable application-level security rules are separate and are not stored here.
 */
final readonly class Rule
{
    public function __construct(
        private int $id,
        private int $knowledgeBaseId,
        private string $name,
        private string $instruction,
        private int $priority,
        private bool $isEnabled,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {}

    public function id(): int
    {
        return $this->id;
    }

    public function knowledgeBaseId(): int
    {
        return $this->knowledgeBaseId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function instruction(): string
    {
        return $this->instruction;
    }

    /**
     * Lower numbers are applied first. Gaps between values are intentional so a rule can be inserted
     * between two others without renumbering.
     */
    public function priority(): int
    {
        return $this->priority;
    }

    public function isEnabled(): bool
    {
        return $this->isEnabled;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
