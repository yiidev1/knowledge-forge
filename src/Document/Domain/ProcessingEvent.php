<?php

declare(strict_types=1);

namespace App\Document\Domain;

use DateTimeImmutable;

/**
 * One entry in a document's processing history. The message is already redacted; safe to display.
 */
final readonly class ProcessingEvent
{
    /**
     * @param array<string, scalar|null> $metadata
     */
    public function __construct(
        public int $id,
        public int $documentId,
        public string $status,
        public ?string $message,
        public array $metadata,
        public DateTimeImmutable $createdAt,
    ) {}
}
