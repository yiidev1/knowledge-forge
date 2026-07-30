<?php

declare(strict_types=1);

namespace App\Ai\Contract\Dto;

/**
 * The current indexing state of a file in a vector store: its status plus any error the provider
 * attached. The error message is already safe to persist and show.
 */
final readonly class IndexState
{
    public function __construct(
        public IndexStatus $status,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public ?int $usageBytes = null,
    ) {}
}
