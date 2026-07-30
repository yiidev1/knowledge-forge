<?php

declare(strict_types=1);

namespace App\Document\Domain;

use App\Ai\Contract\Dto\IndexStatus;
use DateTimeImmutable;

/**
 * One OpenAI artifact produced from a document: a source file or a derived-Markdown file, with the
 * provider file id and its current indexing state.
 */
final readonly class IndexedFile
{
    public function __construct(
        public int $id,
        public int $documentId,
        public IndexedFileRole $role,
        public ?string $derivedPath,
        public ?string $openaiFileId,
        public IndexStatus $indexStatus,
        public ?int $usageBytes,
        public ?string $lastErrorCode,
        public ?string $lastErrorMessage,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    public function isIndexed(): bool
    {
        return $this->indexStatus === IndexStatus::Completed;
    }
}
