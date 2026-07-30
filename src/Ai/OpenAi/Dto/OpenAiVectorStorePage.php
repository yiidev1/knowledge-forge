<?php

declare(strict_types=1);

namespace App\Ai\OpenAi\Dto;

/**
 * One page of vector stores, with the cursor envelope the list endpoint returns.
 *
 * {@see \App\Ai\OpenAi\Client\OpenAiClientInterface::listVectorStores()} drops this envelope and hands
 * back only the first page's rows, which is fine for a lookup but silently wrong for anything that must
 * reason about the whole account. Keeping `hasMore` and `lastId` is what lets a caller tell "that is
 * everything" apart from "that is the first hundred".
 */
final readonly class OpenAiVectorStorePage
{
    /**
     * @param list<OpenAiVectorStore> $data
     */
    public function __construct(
        public array $data,
        public bool $hasMore = false,
        public ?string $lastId = null,
    ) {}
}
