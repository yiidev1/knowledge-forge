<?php

declare(strict_types=1);

namespace App\Ai\OpenAi\Dto;

/**
 * A parsed OpenAI Vector Stores API object. `metadata` is retained so a reconciliation can match a
 * store back to the knowledge base that created it.
 *
 * The billing and lifecycle fields are appended with defaults rather than inserted, so that every
 * existing positional construction keeps compiling and a store parsed by older code simply reports
 * zero/null for them instead of failing.
 */
final readonly class OpenAiVectorStore
{
    /**
     * @param array<string, string>    $metadata
     * @param array<string, mixed>|null $expiresAfter Retention policy (`{anchor, days}`), distinct from
     * `$expiresAt` which is the instant it resolves to — a store can carry a policy with no expiry yet.
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $status,
        public array $metadata,
        public int $createdAt,
        public int $usageBytes = 0,
        public VectorStoreFileCounts $fileCounts = new VectorStoreFileCounts(),
        public ?int $lastActiveAt = null,
        public ?int $expiresAt = null,
        public ?array $expiresAfter = null,
    ) {}
}
