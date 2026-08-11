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
    ) {}

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }
}
