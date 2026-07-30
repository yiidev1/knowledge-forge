<?php

declare(strict_types=1);

namespace App\KnowledgeBase\Application;

use App\KnowledgeBase\Domain\Exception\KnowledgeBaseNotFound;
use App\KnowledgeBase\Domain\KnowledgeBase;
use App\KnowledgeBase\Domain\KnowledgeBaseRepositoryInterface;

use function trim;

/**
 * Updates a knowledge base's editable details: name, description and custom instructions.
 *
 * The slug is deliberately immutable — it is the public identifier and changing it would break every
 * existing link and bookmark. A rename therefore keeps the original slug.
 */
final readonly class UpdateKnowledgeBaseService
{
    public function __construct(
        private KnowledgeBaseRepositoryInterface $repository,
    ) {}

    public function update(int $id, string $name, ?string $description, ?string $systemInstructions): void
    {
        $knowledgeBase = $this->repository->findById($id);

        if (!$knowledgeBase instanceof KnowledgeBase) {
            throw KnowledgeBaseNotFound::withId($id);
        }

        $this->repository->updateDetails(
            $id,
            trim($name),
            $this->normalizeOptional($description),
            $this->normalizeOptional($systemInstructions),
        );
    }

    private function normalizeOptional(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
