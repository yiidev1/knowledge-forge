<?php

declare(strict_types=1);

namespace App\Ai\OpenAi\Dto;

/**
 * One page of the files attached to a vector store, with the cursor envelope.
 *
 * Only file *metadata* is ever carried here — id, status, size, error, timestamps. File content is
 * never requested and never parsed; the dashboard reports what is indexed, not what is in it.
 */
final readonly class OpenAiVectorStoreFilePage
{
    /**
     * @param list<OpenAiVectorStoreFile> $data
     */
    public function __construct(
        public array $data,
        public bool $hasMore = false,
        public ?string $lastId = null,
    ) {}
}
