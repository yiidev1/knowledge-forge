<?php

declare(strict_types=1);

namespace App\KnowledgeBase\Domain;

/**
 * The Order58 source-mapping state of a knowledge base: the backing store id and whether that store is still
 * active upstream. Returned by {@see KnowledgeBaseSourceRepositoryInterface::findOrder58SourceState()} so the
 * chat-availability policy can require an active source in one query (rather than a store-id lookup plus a
 * separate active check).
 */
final readonly class Order58SourceState
{
    public function __construct(
        public int $storeSourceId,
        public bool $active,
    ) {}
}
