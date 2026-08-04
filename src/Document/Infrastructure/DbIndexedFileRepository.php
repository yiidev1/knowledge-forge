<?php

declare(strict_types=1);

namespace App\Document\Infrastructure;

use App\Ai\Contract\Dto\IndexStatus;
use App\Document\Domain\DocumentStatus;
use App\Document\Domain\IndexedFile;
use App\Document\Domain\IndexedFileRepositoryInterface;
use App\Document\Domain\IndexedFileRole;
use App\Shared\Domain\Clock\ClockInterface;
use App\Shared\Infrastructure\Db\DbDateTime;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;

use function is_array;

/**
 * MySQL-backed repository for document index files.
 */
final readonly class DbIndexedFileRepository implements IndexedFileRepositoryInterface
{
    private const TABLE = '{{%document_index_files}}';

    public function __construct(
        private ConnectionInterface $connection,
        private ClockInterface $clock,
    ) {}

    public function createPending(int $documentId, IndexedFileRole $role, ?string $derivedPath): int
    {
        $now = DbDateTime::format($this->clock->now());

        $this->connection->createCommand()->insert(self::TABLE, [
            'document_id' => $documentId,
            'role' => $role->value,
            'derived_path' => $derivedPath,
            'index_status' => IndexStatus::Pending->value,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        return (int) $this->connection->getLastInsertId();
    }

    public function setUploaded(int $indexedFileId, string $openaiFileId, IndexStatus $status): void
    {
        $this->connection->createCommand()->update(
            self::TABLE,
            [
                'openai_file_id' => $openaiFileId,
                'index_status' => $status->value,
                'updated_at' => DbDateTime::format($this->clock->now()),
            ],
            ['id' => $indexedFileId],
        )->execute();
    }

    public function updateState(int $indexedFileId, IndexStatus $status, ?int $usageBytes, ?string $errorCode, ?string $errorMessage): void
    {
        $this->connection->createCommand()->update(
            self::TABLE,
            [
                'index_status' => $status->value,
                'usage_bytes' => $usageBytes,
                'last_error_code' => $errorCode,
                'last_error_message' => $errorMessage,
                'updated_at' => DbDateTime::format($this->clock->now()),
            ],
            ['id' => $indexedFileId],
        )->execute();
    }

    public function clearOpenaiFileId(int $indexedFileId): void
    {
        $this->connection->createCommand()->update(
            self::TABLE,
            ['openai_file_id' => null, 'updated_at' => DbDateTime::format($this->clock->now())],
            ['id' => $indexedFileId],
        )->execute();
    }

    public function findById(int $indexedFileId): ?IndexedFile
    {
        return $this->hydrate($this->query()->where(['id' => $indexedFileId])->limit(1)->one());
    }

    public function findByDocument(int $documentId): array
    {
        $rows = $this->query()
            ->where(['document_id' => $documentId, 'pending_removal' => 0])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        return $this->hydrateAll($rows);
    }

    public function findByOpenaiFileId(string $openaiFileId): ?IndexedFile
    {
        return $this->hydrate($this->query()->where(['openai_file_id' => $openaiFileId])->limit(1)->one());
    }

    public function deleteByDocument(int $documentId): void
    {
        $this->connection->createCommand()->delete(self::TABLE, ['document_id' => $documentId])->execute();
    }

    public function markPendingRemovalByDocument(int $documentId): void
    {
        $this->connection->createCommand()->update(
            self::TABLE,
            ['pending_removal' => 1, 'updated_at' => DbDateTime::format($this->clock->now())],
            ['document_id' => $documentId],
        )->execute();
    }

    public function findPendingRemoval(int $limit): array
    {
        // Replacement guard. A flagged (old) file is eligible for removal ONLY when either:
        //   (a) its document is explicitly deleted or disabled — no replacement is coming; or
        //   (b) a completed, non-flagged replacement DEFINITELY exists for the same document.
        // This is deliberately NOT "no incomplete replacement exists": a resync whose new file has not been
        // created yet (the worker processes one document per run) also has no incomplete sibling, yet its
        // old completed file must be retained until the replacement is actually completed — otherwise the
        // knowledge base is briefly left with no usable copy. A failed replacement is likewise not a
        // completed one, so the old file is retained through a failed refresh.
        $rows = $this->connection->createQuery()
            ->from(['f' => self::TABLE])
            ->where(['f.pending_removal' => 1])
            ->andWhere(new Expression(
                'EXISTS (SELECT 1 FROM {{%documents}} d'
                . ' WHERE d.[[id]] = f.[[document_id]] AND (d.[[status]] = :deleted OR d.[[is_enabled]] = 0))'
                . ' OR EXISTS (SELECT 1 FROM {{%document_index_files}} g'
                . ' WHERE g.[[document_id]] = f.[[document_id]]'
                . ' AND g.[[pending_removal]] = 0 AND g.[[index_status]] = :completed AND g.[[openai_file_id]] IS NOT NULL)',
                [':deleted' => DocumentStatus::Deleted->value, ':completed' => IndexStatus::Completed->value],
            ))
            ->orderBy(['f.id' => SORT_ASC])
            ->limit($limit)
            ->all();

        return $this->hydrateAll($rows);
    }

    public function delete(int $indexedFileId): void
    {
        $this->connection->createCommand()->delete(self::TABLE, ['id' => $indexedFileId])->execute();
    }

    private function query(): \Yiisoft\Db\Query\QueryInterface
    {
        return $this->connection->createQuery()->from(self::TABLE);
    }

    /**
     * @param array<array-key, array|object> $rows
     *
     * @return list<IndexedFile>
     */
    private function hydrateAll(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $indexedFile = $this->hydrate($row);
            if ($indexedFile !== null) {
                $result[] = $indexedFile;
            }
        }

        return $result;
    }

    private function hydrate(array|object|null $row): ?IndexedFile
    {
        if (!is_array($row)) {
            return null;
        }

        return new IndexedFile(
            id: (int) $row['id'],
            documentId: (int) $row['document_id'],
            role: IndexedFileRole::from((string) $row['role']),
            derivedPath: self::nullableString($row['derived_path']),
            openaiFileId: self::nullableString($row['openai_file_id']),
            indexStatus: IndexStatus::from((string) $row['index_status']),
            usageBytes: isset($row['usage_bytes']) && $row['usage_bytes'] !== null ? (int) $row['usage_bytes'] : null,
            lastErrorCode: self::nullableString($row['last_error_code']),
            lastErrorMessage: self::nullableString($row['last_error_message']),
            createdAt: DbDateTime::parse((string) $row['created_at']),
            updatedAt: DbDateTime::parse((string) $row['updated_at']),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
