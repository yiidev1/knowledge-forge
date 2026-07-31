<?php

declare(strict_types=1);

namespace App\Agent\Web\Chat;

use App\Agent\Domain\AgentStoreDirectoryInterface;
use App\KnowledgeBase\Domain\Exception\KnowledgeBaseNotFound;
use App\KnowledgeBase\Domain\KnowledgeBase;
use App\KnowledgeBase\Web\KnowledgeBaseFinder;

/**
 * Resolves a `{slug}` to a knowledge base an agent is allowed to chat with, or a 404.
 *
 * A store that exists but is not available to agents (inactive, disabled, not ready, or with no indexed
 * documents) yields the same not-found result as one that does not exist — its existence is never
 * revealed, and an agent cannot reach it by editing the URL.
 */
final readonly class AgentStoreResolver
{
    public function __construct(
        private KnowledgeBaseFinder $finder,
        private AgentStoreDirectoryInterface $directory,
    ) {}

    public function resolve(string $slug): KnowledgeBase
    {
        $knowledgeBase = $this->finder->bySlug($slug);

        if ($this->directory->findAvailableBySlug($slug) === null) {
            throw KnowledgeBaseNotFound::withSlug($slug);
        }

        return $knowledgeBase;
    }
}
