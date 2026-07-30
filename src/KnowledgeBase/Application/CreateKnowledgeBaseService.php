<?php

declare(strict_types=1);

namespace App\KnowledgeBase\Application;

use App\KnowledgeBase\Domain\KnowledgeBaseRepositoryInterface;
use App\Shared\Application\Transaction\TransactionRunnerInterface;

use function trim;

/**
 * Creates a knowledge base.
 *
 * The slug is derived from the name and the insert happens in one transaction, so a slug generated a
 * moment ago cannot be taken by a concurrent create between the uniqueness check and the write. The
 * vector store is not provisioned here — the row is left pending for the background worker (Phase 6).
 */
final readonly class CreateKnowledgeBaseService
{
    public function __construct(
        private KnowledgeBaseRepositoryInterface $repository,
        private SlugGenerator $slugGenerator,
        private TransactionRunnerInterface $transaction,
    ) {}

    /**
     * @return int The id of the created knowledge base.
     */
    public function create(string $name, ?string $description, ?string $systemInstructions): int
    {
        KnowledgeBaseInputValidator::validate($name, $description, $systemInstructions);

        $name = trim($name);
        $description = $this->normalizeOptional($description);
        $systemInstructions = $this->normalizeOptional($systemInstructions);

        return $this->transaction->run(function () use ($name, $description, $systemInstructions): int {
            $slug = $this->slugGenerator->generate($name);

            return $this->repository->create($name, $slug, $description, $systemInstructions);
        });
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
