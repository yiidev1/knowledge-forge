<?php

declare(strict_types=1);

namespace App\Ai\OpenAi\Dto;

/**
 * A parsed OpenAI vector-store file object.
 *
 * Its `id` is the same `file-…` value returned by the Files API — the two are one identifier, which is
 * why the application stores a single `openai_file_id` rather than two columns. The adapter asserts this
 * equality on attach and raises an invalid-response error if the API ever diverges.
 */
final readonly class OpenAiVectorStoreFile
{
    /**
     * @param array<string, string>|null $attributes Provider-side key/value tags, string values only.
     */
    public function __construct(
        public string $id,
        public string $vectorStoreId,
        public string $status,
        public ?string $lastErrorCode,
        public ?string $lastErrorMessage,
        public ?int $usageBytes,
        // Appended with defaults so existing positional construction keeps compiling.
        public ?int $createdAt = null,
        public ?string $chunkingStrategy = null,
        public ?array $attributes = null,
    ) {}
}
