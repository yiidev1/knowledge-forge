<?php

declare(strict_types=1);

namespace App\Document\Infrastructure;

use App\Document\Domain\DocumentKind;
use App\Document\Domain\DocumentStatus;
use App\Document\Domain\GeneratedDocument;
use App\Document\Domain\GeneratedDocumentRepositoryInterface;
use App\Shared\Infrastructure\Db\DbDateTime;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;

use function is_array;
use function mb_substr;

/**
 * MySQL-backed persistence for generated documents, over the shared `documents` table.
 */
final readonly class DbGeneratedDocumentRepository implements GeneratedDocumentRepositoryInterface
{
    private const TABLE = '{{%documents}}';

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function findBySource(int $knowledgeBaseId, string $sourceType, string $sourceRef): ?GeneratedDocument
    {
        $row = $this->connection
            ->createQuery()
            ->select(['id', 'source_sync_hash', 'status', 'stored_path', 'storage_token', 'is_source_overridden', 'is_enabled'])
            ->from(self::TABLE)
            ->where([
                'knowledge_base_id' => $knowledgeBaseId,
                'source_type' => $sourceType,
                'source_ref' => $sourceRef,
            ])
            ->limit(1)
            ->one();

        if (!is_array($row)) {
            return null;
        }

        return new GeneratedDocument(
            id: (int) $row['id'],
            sourceSyncHash: $row['source_sync_hash'] === null ? null : (string) $row['source_sync_hash'],
            status: (string) $row['status'],
            storedPath: (string) $row['stored_path'],
            storageToken: (string) $row['storage_token'],
            isSourceOverridden: (bool) (int) ($row['is_source_overridden'] ?? 0),
            isEnabled: (bool) (int) ($row['is_enabled'] ?? 1),
        );
    }

    public function create(
        int $knowledgeBaseId,
        string $sourceType,
        string $sourceRef,
        string $title,
        string $syncHash,
        string $storedPath,
        string $storageToken,
        string $checksum,
        int $sizeBytes,
        DateTimeImmutable $now,
    ): int {
        $ts = DbDateTime::format($now);
        $display = self::truncate($title === '' ? $sourceType : $title, 255);

        $this->connection->createCommand()->insert(self::TABLE, [
            'knowledge_base_id' => $knowledgeBaseId,
            'original_filename' => $display,
            'stored_path' => $storedPath,
            'storage_token' => $storageToken,
            'mime_type' => 'text/markdown',
            'extension' => 'md',
            'size_bytes' => $sizeBytes,
            'checksum_sha256' => $checksum,
            'kind' => DocumentKind::Text->value,
            'status' => DocumentStatus::Queued->value,
            'priority' => 0,
            'processing_attempts' => 0,
            'source_type' => $sourceType,
            'source_ref' => $sourceRef,
            'source_sync_hash' => $syncHash,
            'title' => $display,
            'is_enabled' => 1,
            'created_at' => $ts,
            'updated_at' => $ts,
        ])->execute();

        return (int) $this->connection->getLastInsertId();
    }

    public function reindex(
        int $id,
        string $title,
        string $syncHash,
        string $checksum,
        int $sizeBytes,
        DateTimeImmutable $now,
    ): void {
        $ts = DbDateTime::format($now);
        $display = self::truncate($title, 255);

        $this->connection->createCommand()->update(
            self::TABLE,
            [
                'original_filename' => $display,
                'title' => $display,
                'source_sync_hash' => $syncHash,
                'checksum_sha256' => $checksum,
                'size_bytes' => $sizeBytes,
                // Requeue fresh so the existing processing drainer re-indexes the replacement.
                'status' => DocumentStatus::Queued->value,
                'processing_attempts' => 0,
                'next_attempt_at' => null,
                'processing_started_at' => null,
                'error_code' => null,
                'error_message' => null,
                'processed_at' => null,
                'deleted_at' => null,
                'is_enabled' => 1,
                'updated_at' => $ts,
            ],
            ['id' => $id],
        )->execute();
    }

    public function disable(int $id, DateTimeImmutable $now): void
    {
        $ts = DbDateTime::format($now);

        $this->connection->createCommand()->update(
            self::TABLE,
            [
                'status' => DocumentStatus::Deleted->value,
                'is_enabled' => 0,
                'deleted_at' => $ts,
                'updated_at' => $ts,
            ],
            ['id' => $id],
        )->execute();
    }

    private static function truncate(string $value, int $max): string
    {
        return mb_substr($value, 0, $max);
    }
}
