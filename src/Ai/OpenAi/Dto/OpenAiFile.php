<?php

declare(strict_types=1);

namespace App\Ai\OpenAi\Dto;

/**
 * A parsed OpenAI Files API object. Only the fields the application uses are kept.
 */
final readonly class OpenAiFile
{
    public function __construct(
        public string $id,
        public string $filename,
        public string $purpose,
        public int $bytes,
        public int $createdAt,
    ) {}
}
