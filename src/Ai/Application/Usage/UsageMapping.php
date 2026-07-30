<?php

declare(strict_types=1);

namespace App\Ai\Application\Usage;

/**
 * One row of the local↔remote reconciliation.
 *
 * The four states are diagnostic only. Nothing on this page acts on them: an orphaned remote store is
 * reported, never deleted, because "this application does not know about it" is not the same as "nobody
 * needs it" — another environment may point at the same OpenAI account.
 */
final readonly class UsageMapping
{
    /** Local knowledge base and remote store agree. */
    public const STATE_MATCHED = 'matched';

    /** A remote store no knowledge base in this application references. */
    public const STATE_REMOTE_UNMAPPED = 'remote_unmapped';

    /** A knowledge base whose stored vector-store id is absent from the remote inventory. */
    public const STATE_LOCAL_MISSING_REMOTE = 'local_missing_remote';

    /** Both sides exist but disagree about readiness. */
    public const STATE_STATUS_MISMATCH = 'status_mismatch';

    /** A knowledge base that has not been provisioned a store yet — expected, not a fault. */
    public const STATE_NOT_PROVISIONED = 'not_provisioned';

    public function __construct(
        public string $state,
        public ?int $knowledgeBaseId = null,
        public ?string $knowledgeBaseName = null,
        public ?string $knowledgeBaseSlug = null,
        public ?string $localVectorStoreStatus = null,
        public ?string $remoteVectorStoreId = null,
        public ?string $remoteStatus = null,
        public ?int $localDocumentCount = null,
        public ?int $localReadyDocumentCount = null,
        public ?int $remoteFileCount = null,
        public bool $archived = false,
    ) {}

    public function isProblem(): bool
    {
        return $this->state === self::STATE_REMOTE_UNMAPPED
            || $this->state === self::STATE_LOCAL_MISSING_REMOTE
            || $this->state === self::STATE_STATUS_MISMATCH;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'knowledge_base_id' => $this->knowledgeBaseId,
            'knowledge_base_name' => $this->knowledgeBaseName,
            'knowledge_base_slug' => $this->knowledgeBaseSlug,
            'local_vector_store_status' => $this->localVectorStoreStatus,
            'remote_vector_store_id' => $this->remoteVectorStoreId,
            'remote_status' => $this->remoteStatus,
            'local_document_count' => $this->localDocumentCount,
            'local_ready_document_count' => $this->localReadyDocumentCount,
            'remote_file_count' => $this->remoteFileCount,
            'archived' => $this->archived,
        ];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            state: SnapshotData::string($data, 'state', self::STATE_REMOTE_UNMAPPED),
            knowledgeBaseId: SnapshotData::nullableInt($data, 'knowledge_base_id'),
            knowledgeBaseName: SnapshotData::nullableString($data, 'knowledge_base_name'),
            knowledgeBaseSlug: SnapshotData::nullableString($data, 'knowledge_base_slug'),
            localVectorStoreStatus: SnapshotData::nullableString($data, 'local_vector_store_status'),
            remoteVectorStoreId: SnapshotData::nullableString($data, 'remote_vector_store_id'),
            remoteStatus: SnapshotData::nullableString($data, 'remote_status'),
            localDocumentCount: SnapshotData::nullableInt($data, 'local_document_count'),
            localReadyDocumentCount: SnapshotData::nullableInt($data, 'local_ready_document_count'),
            remoteFileCount: SnapshotData::nullableInt($data, 'remote_file_count'),
            archived: SnapshotData::bool($data, 'archived'),
        );
    }
}
