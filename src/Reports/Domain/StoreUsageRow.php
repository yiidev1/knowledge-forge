<?php

declare(strict_types=1);

namespace App\Reports\Domain;

use DateTimeImmutable;

/**
 * One knowledge base's activity — the view that shows which store's knowledge needs work.
 *
 * A high fallback count with a low average rating is the signal: agents are asking, and the documents are
 * not answering.
 */
final readonly class StoreUsageRow
{
    public function __construct(
        public int $knowledgeBaseId,
        public string $storeName,
        public ChatTypeFilter $chatType,
        public int $questions,
        public int $uniqueAgents,
        public int $ratedAnswers,
        public ?float $averageRating,
        public int $lowRatings,
        public int $fallbackAnswers,
        public ?DateTimeImmutable $lastActivityAt,
    ) {}

    public function isRuleChat(): bool
    {
        return $this->chatType === ChatTypeFilter::Rule;
    }
}
