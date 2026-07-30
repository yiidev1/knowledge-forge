<?php

declare(strict_types=1);

namespace App\Ai\Contract\Dto;

/**
 * The outcome of indexing a file: the provider file id (which the application records so it can poll,
 * detach, and resolve citations later) and the file's state immediately after attachment.
 */
final readonly class IndexedFileResult
{
    public function __construct(
        public string $openaiFileId,
        public IndexState $state,
    ) {}
}
