<?php

declare(strict_types=1);

namespace App\KnowledgeBase\Web;

use App\KnowledgeBase\Domain\Exception\KnowledgeBaseNotFound;
use App\KnowledgeBase\Domain\KnowledgeBase;
use App\KnowledgeBase\Domain\KnowledgeBaseRepositoryInterface;

/**
 * Resolves the `{slug}` route parameter to a knowledge base, or a 404.
 *
 * Shared by every knowledge-base action so the "load or fail" step is written once and always throws
 * the same not-found exception, which {@see \App\Shared\Web\Middleware\DomainExceptionMiddleware}
 * renders as a plain 404.
 */
final readonly class KnowledgeBaseFinder
{
    public function __construct(
        private KnowledgeBaseRepositoryInterface $repository,
    ) {}

    public function bySlug(string $slug): KnowledgeBase
    {
        $knowledgeBase = $this->repository->findBySlug($slug);

        if (!$knowledgeBase instanceof KnowledgeBase) {
            throw KnowledgeBaseNotFound::withSlug($slug);
        }

        return $knowledgeBase;
    }
}
