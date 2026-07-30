<?php

declare(strict_types=1);

namespace App\Ai\Application\Usage;

/**
 * One data source that failed during a sync, recorded so the page can show exactly what is missing.
 *
 * A partial outage must degrade a section, never the page. Naming the source that failed is the
 * difference between "some numbers are missing" and an operator being able to tell whether the store
 * inventory, one store's file list, or the organization API is the thing that broke.
 *
 * `$message` is always an already-safe string — the `getMessage()` of an `AiException`, which the error
 * mapper has already redacted. Raw provider response bodies are never carried here.
 */
final readonly class SyncProblem
{
    public const SOURCE_VECTOR_STORES = 'vector_stores';
    public const SOURCE_VECTOR_STORE_FILES = 'vector_store_files';
    public const SOURCE_ORGANIZATION = 'organization';

    public function __construct(
        public string $source,
        public string $message,
        public ?string $subject = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'message' => $this->message,
            'subject' => $this->subject,
        ];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            source: SnapshotData::string($data, 'source', 'unknown'),
            message: SnapshotData::string($data, 'message', 'Unknown problem.'),
            subject: SnapshotData::nullableString($data, 'subject'),
        );
    }
}
