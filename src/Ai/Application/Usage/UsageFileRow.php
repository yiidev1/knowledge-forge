<?php

declare(strict_types=1);

namespace App\Ai\Application\Usage;

/**
 * One file attached to a vector store, as metadata only.
 *
 * There is no content field and there never should be. The Vector Store Files endpoint returns what is
 * indexed, not what it says, and the dashboard reports indexing state — size, status, why it failed —
 * so an operator can tell a stuck file from a healthy one without ever reading a customer document.
 */
final readonly class UsageFileRow
{
    public function __construct(
        public string $id,
        public string $status,
        public ?int $usageBytes,
        public ?int $createdAt,
        public ?string $lastErrorCode,
        public ?string $lastErrorMessage,
        public ?string $chunkingStrategy = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'usage_bytes' => $this->usageBytes,
            'created_at' => $this->createdAt,
            'last_error_code' => $this->lastErrorCode,
            'last_error_message' => $this->lastErrorMessage,
            'chunking_strategy' => $this->chunkingStrategy,
        ];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: SnapshotData::string($data, 'id'),
            status: SnapshotData::string($data, 'status', 'unknown'),
            usageBytes: SnapshotData::nullableInt($data, 'usage_bytes'),
            createdAt: SnapshotData::nullableInt($data, 'created_at'),
            lastErrorCode: SnapshotData::nullableString($data, 'last_error_code'),
            lastErrorMessage: SnapshotData::nullableString($data, 'last_error_message'),
            chunkingStrategy: SnapshotData::nullableString($data, 'chunking_strategy'),
        );
    }
}
