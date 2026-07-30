<?php

declare(strict_types=1);

namespace App\Document\Infrastructure;

use App\Document\Domain\Document;
use App\Document\Domain\DocumentKind;
use App\Document\Domain\DocumentProcessingRepositoryInterface;
use App\Document\Domain\DocumentStatus;
use App\Shared\Domain\Clock\ClockInterface;
use App\Shared\Infrastructure\Db\DbDateTime;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\QueryInterface;

use function is_array;

/**
 * MySQL-backed worker view of documents. Claims and recovery are conditional updates, so concurrency is
 * safe without table locks.
 */
final readonly class DbDocumentProcessingRepository implements DocumentProcessingRepositoryInterface
{
    private const TABLE = '{{%documents}}';

    public function __construct(
        private ConnectionInterface $connection,
        private ClockInterface $clock,
    ) {}

    public function findProcessable(int $limit, DateTimeImmutable $now): array
    {
        $rows = $this->query()
            ->where(['status' => [DocumentStatus::Queued->value, DocumentStatus::Indexing->value]])
            ->andWhere(['or', ['next_attempt_at' => null], ['<=', 'next_attempt_at', DbDateTime::format($now)]])
            ->orderBy(['priority' => SORT_DESC, 'next_attempt_at' => SORT_ASC, 'id' => SORT_ASC])
            ->limit($limit)
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

    public function find(int $documentId): ?Document
    {
        return $this->hydrate($this->query()->where(['id' => $documentId])->limit(1)->one());
    }

    public function claim(int $documentId, DocumentStatus $expected, DateTimeImmutable $now): bool
    {
        $values = [
            'status' => DocumentStatus::Processing->value,
            'processing_started_at' => DbDateTime::format($now),
            'updated_at' => DbDateTime::format($now),
        ];

        // A fresh processing attempt is only counted when coming from queued; a poll of an indexing
        // document is not a new attempt.
        if ($expected === DocumentStatus::Queued) {
            $values['processing_attempts'] = new Expression('processing_attempts + 1');
        }

        $affected = $this->connection->createCommand()->update(
            self::TABLE,
            $values,
            ['id' => $documentId, 'status' => $expected->value],
        )->execute();

        return $affected === 1;
    }

    public function markReady(int $documentId, DateTimeImmutable $now): void
    {
        $this->connection->createCommand()->update(
            self::TABLE,
            [
                'status' => DocumentStatus::Ready->value,
                'processed_at' => DbDateTime::format($now),
                'error_code' => null,
                'error_message' => null,
                'next_attempt_at' => null,
                'updated_at' => DbDateTime::format($now),
            ],
            ['id' => $documentId],
        )->execute();
    }

    public function markIndexing(int $documentId, DateTimeImmutable $nextAttemptAt): void
    {
        $now = DbDateTime::format($this->clock->now());

        $this->connection->createCommand()->update(
            self::TABLE,
            [
                'status' => DocumentStatus::Indexing->value,
                'next_attempt_at' => DbDateTime::format($nextAttemptAt),
                'updated_at' => $now,
            ],
            ['id' => $documentId],
        )->execute();
    }

    public function requeue(int $documentId, DateTimeImmutable $nextAttemptAt, ?string $errorCode, ?string $errorMessage): void
    {
        $now = DbDateTime::format($this->clock->now());

        $this->connection->createCommand()->update(
            self::TABLE,
            [
                'status' => DocumentStatus::Queued->value,
                'next_attempt_at' => DbDateTime::format($nextAttemptAt),
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'updated_at' => $now,
            ],
            ['id' => $documentId],
        )->execute();
    }

    public function markFailed(int $documentId, ?string $errorCode, ?string $errorMessage): void
    {
        $now = DbDateTime::format($this->clock->now());

        $this->connection->createCommand()->update(
            self::TABLE,
            [
                'status' => DocumentStatus::Failed->value,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'next_attempt_at' => null,
                'updated_at' => $now,
            ],
            ['id' => $documentId],
        )->execute();
    }

    public function recoverStuck(DateTimeImmutable $threshold, DateTimeImmutable $now): int
    {
        return $this->connection->createCommand()->update(
            self::TABLE,
            [
                'status' => DocumentStatus::Queued->value,
                'updated_at' => DbDateTime::format($now),
            ],
            ['and',
                ['status' => DocumentStatus::Processing->value],
                ['<', 'processing_started_at', DbDateTime::format($threshold)],
            ],
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
