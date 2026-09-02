<?php

declare(strict_types=1);

namespace App\Order58\Domain;

/**
 * The parameters of an admin store-directory lookup: free-text search, a status {@see StoreDirectoryFilter},
 * an alphabet bucket ("all", "A".."Z" or "#"), and 1-based pagination. Kept as a value object so the reader
 * and its tests share one shape.
 */
final readonly class StoreDirectoryQuery
{
    public function __construct(
        public string $search = '',
        public StoreDirectoryFilter $filter = StoreDirectoryFilter::All,
        public string $letter = 'all',
        public int $page = 1,
        public int $perPage = 24,
        /**
         * Independent chat-availability axis: restrict to stores that can (or cannot) open chat right now by the
         * canonical eligibility policy. {@see StoreChatAvailabilityFilter::All} applies no restriction.
         */
        public StoreChatAvailabilityFilter $chatAvailability = StoreChatAvailabilityFilter::All,
        /** Independent Order58 source-active axis (separate from the knowledge-pipeline {@see $filter}). */
        public StoreSourceStatusFilter $sourceStatus = StoreSourceStatusFilter::All,
        /** Independent admin agent-access axis. */
        public StoreAgentAvailabilityFilter $agentAvailability = StoreAgentAvailabilityFilter::All,
        /**
         * Restrict the directory to these store source ids, or null for no restriction.
         *
         * Deliberately generic — a plain "only these stores" — rather than an axis named after
         * whatever the caller is filtering on. It exists so a page can narrow the directory by a fact
         * this reader knows nothing about (the store-audio picker restricts to stores that have
         * conversions), while the rows, the total and the letter counts are still computed together
         * and therefore still agree.
         *
         * An **empty list matches nothing**, which is not the same as null: "no store qualified" and
         * "do not restrict" are different answers and must render differently.
         *
         * @var list<int>|null
         */
        public ?array $sourceIds = null,
    ) {}

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }
}
