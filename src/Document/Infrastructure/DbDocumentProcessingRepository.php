<?php

declare(strict_types=1);

namespace App\Document\Infrastructure;

use App\Document\Domain\Document;
use App\Document\Domain\DocumentKind;
use App\Document\Domain\DocumentProcessingRepositoryInterface;
use App\Document\Domain\DocumentStatus;
use App\KnowledgeBase\Domain\VectorStoreStatus;
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
    private const KNOWLEDGE_BASES_TABLE = '{{%knowledge_bases}}';

    public function __construct(
        private ConnectionInterface $connection,
        private ClockInterface $clock,
    ) {}

    public function findProcessable(int $limit, DateTimeImmutable $now): array
    {
        // The knowledge base must already be provisioned, and that has to be expressed HERE rather than
        // checked after the query returns. The database applies the LIMIT, so a document whose vector
        // store is not ready would otherwise occupy a slot in the batch only to be discarded in PHP.
        // With a small batch size that starves every document behind it, and because such a document is
        // deliberately skipped WITHOUT being claimed, its next_attempt_at stays null and it holds the
        // head of the ordering on every subsequent run — the queue stops moving permanently.
        $rows = $this->connection->createQuery()
            ->select('d.*')
            ->from(['d' => self::TABLE])
            ->innerJoin(['kb' => self::KNOWLEDGE_BASES_TABLE], 'kb.id = d.knowledge_base_id')
            ->where(['d.status' => [DocumentStatus::Queued->value, DocumentStatus::Indexing->value]])
            ->andWhere(['kb.vector_store_status' => VectorStoreStatus::Ready->value])
            ->andWhere(['not', ['kb.openai_vector_store_id' => null]])
            ->andWhere(['or', ['d.next_attempt_at' => null], ['<=', 'd.next_attempt_at', DbDateTime::format($now)]])
            // Work already begun outranks work not yet started.
            //
            // The WHERE above admits a row only when next_attempt_at is null or already past, so a row
            // that HAS a timestamp here is by definition due: an indexing document waiting to be polled,
            // or one requeued after a transient failure. Both are finishing something already started —
            // and, for an indexing document, an OpenAI upload already paid for — so they go first.
            //
            // `next_attempt_at IS NULL ASC` is what expresses that. MySQL sorts NULL FIRST in a plain
            // ASC, so without this clause never-scheduled documents outrank every due poll and retry:
            // that is exactly how documents reached `indexing` and then sat there while a backlog of
            // fresh uploads was processed ahead of them.
            ->orderBy(new Expression(
                '{{d}}.[[priority]] DESC, {{d}}.[[next_attempt_at]] IS NULL ASC, '
                . '{{d}}.[[next_attempt_at]] ASC, {{d}}.[[id]] ASC',
            ))
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
