<?php

declare(strict_types=1);

namespace App\Ai\Application\Usage;

use App\Ai\OpenAi\Dto\VectorStoreFileCounts;

use function array_map;

/**
 * One vector store as the dashboard shows it.
 *
 * The store id is carried in full. Everywhere else in this application that id is infrastructure detail
 * the domain layer forbids rendering ({@see \App\KnowledgeBase\Domain\KnowledgeBase::openaiVectorStoreId()}),
 * and that rule is right for user-facing pages. This page is the deliberate exception: it is an
 * admin-only reconciliation tool whose entire job is to line up remote ids against local records, and it
 * cannot do that with the id hidden. The template shortens it for display and keeps the full value
 * behind a copy control.
 */
final readonly class UsageStoreRow
{
    /**
     * @param array<string, string> $metadata
     * @param list<UsageFileRow>    $files            Empty when detail was not fetched for this store.
     * @param ?string               $fileDetailProblem Why the file list is missing, when it is.
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $status,
        public int $usageBytes,
        public VectorStoreFileCounts $fileCounts,
        public ?int $createdAt,
        public ?int $lastActiveAt,
        public ?int $expiresAt,
        public array $metadata = [],
        public array $files = [],
        public ?string $fileDetailProblem = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status,
            'usage_bytes' => $this->usageBytes,
            'file_counts' => $this->fileCounts->toArray(),
            'created_at' => $this->createdAt,
            'last_active_at' => $this->lastActiveAt,
            'expires_at' => $this->expiresAt,
            'metadata' => $this->metadata,
            'files' => array_map(static fn(UsageFileRow $f): array => $f->toArray(), $this->files),
            'file_detail_problem' => $this->fileDetailProblem,
        ];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $files = [];
        foreach (SnapshotData::rows($data, 'files') as $row) {
            $files[] = UsageFileRow::fromArray($row);
        }

        return new self(
            id: SnapshotData::string($data, 'id'),
            name: SnapshotData::string($data, 'name'),
            status: SnapshotData::string($data, 'status', 'unknown'),
            usageBytes: SnapshotData::int($data, 'usage_bytes'),
            fileCounts: VectorStoreFileCounts::fromArray(SnapshotData::array($data, 'file_counts')),
            createdAt: SnapshotData::nullableInt($data, 'created_at'),
            lastActiveAt: SnapshotData::nullableInt($data, 'last_active_at'),
            expiresAt: SnapshotData::nullableInt($data, 'expires_at'),
            metadata: SnapshotData::stringMap($data, 'metadata'),
            files: $files,
            fileDetailProblem: SnapshotData::nullableString($data, 'file_detail_problem'),
        );
    }
}
