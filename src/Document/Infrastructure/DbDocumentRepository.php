<?php

declare(strict_types=1);

namespace App\Document\Infrastructure;

use App\Document\Domain\Document;
use App\Document\Domain\DocumentKind;
use App\Document\Domain\DocumentRepositoryInterface;
use App\Document\Domain\DocumentStatus;
use App\Document\Domain\NewDocument;
use App\Shared\Domain\Clock\ClockInterface;
use App\Shared\Infrastructure\Db\DbDateTime;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\QueryInterface;

use function is_array;

/**
 * MySQL-backed document repository. Returns typed entities; scoped by knowledge base where relevant.
 */
final readonly class DbDocumentRepository implements DocumentRepositoryInterface
{
    private const TABLE = '{{%documents}}';

    public function __construct(
        private ConnectionInterface $connection,
        private ClockInterface $clock,
    ) {}

    public function findByIdForKnowledgeBase(int $documentId, int $knowledgeBaseId): ?Document
    {
        return $this->hydrate(
            $this->query()
                ->where(['id' => $documentId, 'knowledge_base_id' => $knowledgeBaseId])
                ->andWhere(['<>', 'status', DocumentStatus::Deleted->value])
                ->limit(1)
                ->one(),
        );
    }

    public function findAllForKnowledgeBase(int $knowledgeBaseId): array
    {
        $rows = $this->query()
            ->where(['knowledge_base_id' => $knowledgeBaseId])
            ->andWhere(['<>', 'status', DocumentStatus::Deleted->value])
            ->orderBy(['created_at' => SORT_DESC, 'id' => SORT_DESC])
            ->all();

        $result = [];
        foreach ($rows as $row) {
            $document = $this->hydrate($row);
            if ($document !== null) {
                $result[] = $document;
            }
        }

        return $result;
    }

    public function countLiveForKnowledgeBase(int $knowledgeBaseId): int
    {
        return (int) $this->query()
            ->where(['knowledge_base_id' => $knowledgeBaseId])
            ->andWhere(['<>', 'status', DocumentStatus::Deleted->value])
            ->count();
    }

    public function countReadyForKnowledgeBase(int $knowledgeBaseId): int
    {
        // A disabled document is removed from the vector store, so it does not count toward chat readiness.
        return (int) $this->query()
            ->where(['knowledge_base_id' => $knowledgeBaseId, 'status' => DocumentStatus::Ready->value, 'is_enabled' => 1])
            ->count();
    }

    public function liveChecksumExists(string $checksum, int $knowledgeBaseId): bool
    {
        return (int) $this->query()
            ->where([
                'knowledge_base_id' => $knowledgeBaseId,
                'checksum_sha256' => $checksum,
            ])
            ->andWhere(['<>', 'status', DocumentStatus::Deleted->value])
            ->count() > 0;
    }

    public function createQueued(NewDocument $document): int
    {
        $now = DbDateTime::format($this->clock->now());

        $this->connection->createCommand()->insert(self::TABLE, [
            'knowledge_base_id' => $document->knowledgeBaseId,
            'original_filename' => $document->originalFilename,
            'stored_path' => $document->storedPath,
            'storage_token' => $document->storageToken,
            'mime_type' => $document->mimeType,
            'extension' => $document->extension,
            'size_bytes' => $document->sizeBytes,
            'checksum_sha256' => $document->checksumSha256,
            'kind' => $document->kind->value,
            'source_type' => $document->sourceType->value,
            'title' => $document->title ?? $document->originalFilename,
            'source_text' => $document->sourceText,
            'status' => DocumentStatus::Queued->value,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        return (int) $this->connection->getLastInsertId();
    }

    public function markDeleted(int $documentId): void
    {
        $now = DbDateTime::format($this->clock->now());

        $this->connection->createCommand()->update(
            self::TABLE,
            [
                'status' => DocumentStatus::Deleted->value,
                'deleted_at' => $now,
                'updated_at' => $now,
            ],
            ['id' => $documentId],
        )->execute();
    }

    public function requeueFresh(int $documentId): void
    {
        $now = DbDateTime::format($this->clock->now());

        $this->connection->createCommand()->update(
            self::TABLE,
            [
                'status' => DocumentStatus::Queued->value,
                'processing_attempts' => 0,
                'processing_started_at' => null,
                'next_attempt_at' => null,
                'error_code' => null,
                'error_message' => null,
                'processed_at' => null,
                'updated_at' => $now,
            ],
            ['id' => $documentId],
        )->execute();
    }

    public function bumpPriority(int $documentId): void
    {
        $this->connection->createCommand()->update(
            self::TABLE,
            [
                'priority' => 1,
                'next_attempt_at' => null,
                'updated_at' => DbDateTime::format($this->clock->now()),
            ],
            ['id' => $documentId],
        )->execute();
    }

    private function query(): QueryInterface
    {
        return $this->connection->createQuery()->from(self::TABLE);
    }

    private function hydrate(array|object|null $row): ?Document
    {
        if (!is_array($row)) {
            return null;
        }

        return new Document(
            id: (int) $row['id'],
            knowledgeBaseId: (int) $row['knowledge_base_id'],
            originalFilename: (string) $row['original_filename'],
            storedPath: (string) $row['stored_path'],
            storageToken: (string) $row['storage_token'],
            mimeType: (string) $row['mime_type'],
            extension: (string) $row['extension'],
            sizeBytes: (int) $row['size_bytes'],
            checksumSha256: (string) $row['checksum_sha256'],
            kind: DocumentKind::from((string) $row['kind']),
            status: DocumentStatus::from((string) $row['status']),
            processingAttempts: (int) $row['processing_attempts'],
            errorCode: self::nullableString($row['error_code']),
            errorMessage: self::nullableString($row['error_message']),
            processedAt: DbDateTime::parseNullable(self::nullableString($row['processed_at'])),
            createdAt: DbDateTime::parse((string) $row['created_at']),
            updatedAt: DbDateTime::parse((string) $row['updated_at']),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
