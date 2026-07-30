<?php

declare(strict_types=1);

namespace App\KnowledgeBase\Application;

use App\KnowledgeBase\Domain\Exception\KnowledgeBaseNotFound;
use App\KnowledgeBase\Domain\KnowledgeBase;
use App\KnowledgeBase\Domain\KnowledgeBaseRepositoryInterface;
use App\KnowledgeBase\Domain\KnowledgeBaseStatus;

/**
 * Archives and restores a knowledge base.
 *
 * A soft, reversible state change: the documents, rules and vector store are all kept. Archiving hides
 * the knowledge base from the default list and blocks chat; restoring brings it back. Hard deletion is
 * intentionally not offered here because it would also destroy uploaded documents and their remote
 * store — that belongs to a later, explicit "delete everything" flow.
 */
final readonly class ArchiveKnowledgeBaseService
{
    public function __construct(
        private KnowledgeBaseRepositoryInterface $repository,
    ) {}

    public function archive(int $id): void
    {
        $this->setStatus($id, KnowledgeBaseStatus::Archived);
    }

    public function restore(int $id): void
    {
        $this->setStatus($id, KnowledgeBaseStatus::Active);
    }

    private function setStatus(int $id, KnowledgeBaseStatus $status): void
    {
        $knowledgeBase = $this->repository->findById($id);

        if (!$knowledgeBase instanceof KnowledgeBase) {
            throw KnowledgeBaseNotFound::withId($id);
        }

        $this->repository->updateStatus($id, $status);
    }
}
