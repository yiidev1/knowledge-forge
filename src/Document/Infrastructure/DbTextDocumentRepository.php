<?php

declare(strict_types=1);

namespace App\Document\Infrastructure;

use App\Document\Domain\DocumentKind;
use App\Document\Domain\DocumentListItem;
use App\Document\Domain\DocumentSourceType;
use App\Document\Domain\DocumentStatus;
use App\Document\Domain\EditableTextDocument;
use App\Document\Domain\TextDocumentRepositoryInterface;
use App\Shared\Infrastructure\Db\DbDateTime;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;

use function is_array;
use function is_string;

/**
 * MySQL-backed text-document operations over the `documents` table.
 */
final readonly class DbTextDocumentRepository implements TextDocumentRepositoryInterface
{
    private const TABLE = '{{%documents}}';

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function findEditable(int $documentId, int $knowledgeBaseId): ?EditableTextDocument
    {
        $row = $this->connection
            ->createQuery()
            ->select(['id', 'source_type', 'title', 'original_filename', 'source_text', 'checksum_sha256', 'stored_path', 'is_source_overridden'])
            ->from(self::TABLE)
            ->where(['id' => $documentId, 'knowledge_base_id' => $knowledgeBaseId])
            ->andWhere(['<>', 'status', DocumentStatus::Deleted->value])
            ->limit(1)
            ->one();

        if (!is_array($row)) {
            return null;
        }

        $sourceText = $row['source_text'] === null ? '' : (string) $row['source_text'];

        return new EditableTextDocument(
            id: (int) $row['id'],
            sourceType: DocumentSourceType::from((string) $row['source_type']),
            title: $this->title($row['title'] ?? null, $row['original_filename'] ?? null),
            sourceText: $sourceText,
            checksum: (string) $row['checksum_sha256'],
            storedPath: (string) $row['stored_path'],
            isSourceOverridden: (bool) (int) ($row['is_source_overridden'] ?? 0),
        );
    }

    public function replaceContent(
        int $documentId,
        string $title,
        string $sourceText,
        string $checksum,
        int $sizeBytes,
        DateTimeImmutable $now,
    ): void {
        $ts = DbDateTime::format($now);

        $this->connection->createCommand()->update(
            self::TABLE,
            [
                'title' => $title,
                'original_filename' => $title,
                'source_text' => $sourceText,
                'checksum_sha256' => $checksum,
                'source_sync_hash' => $checksum,
                'size_bytes' => $sizeBytes,
                // Requeue fresh so the existing processing drainer re-indexes the replacement.
                'status' => DocumentStatus::Queued->value,
                'processing_attempts' => 0,
                'processing_started_at' => null,
                'next_attempt_at' => null,
                'error_code' => null,
                'error_message' => null,
                'processed_at' => null,
                'is_enabled' => 1,
                'updated_at' => $ts,
            ],
            ['id' => $documentId],
        )->execute();
    }

    public function updateMetadata(int $documentId, string $title, string $sourceText, DateTimeImmutable $now): void
    {
        $ts = DbDateTime::format($now);

        $this->connection->createCommand()->update(
            self::TABLE,
            ['title' => $title, 'original_filename' => $title, 'source_text' => $sourceText, 'updated_at' => $ts],
            ['id' => $documentId],
        )->execute();
    }

    public function setEnabled(int $documentId, int $knowledgeBaseId, bool $enabled, DateTimeImmutable $now): void
    {
        $this->connection->createCommand()->update(
            self::TABLE,
            ['is_enabled' => $enabled ? 1 : 0, 'updated_at' => DbDateTime::format($now)],
            ['id' => $documentId, 'knowledge_base_id' => $knowledgeBaseId],
        )->execute();
    }

    public function findListForKnowledgeBase(int $knowledgeBaseId): array
    {
        $rows = $this->connection
            ->createQuery()
            ->select(['id', 'title', 'original_filename', 'source_type', 'kind', 'status', 'is_enabled', 'size_bytes', 'error_message', 'created_at', 'is_source_overridden'])
            ->from(self::TABLE)
            ->where(['knowledge_base_id' => $knowledgeBaseId])
            ->andWhere(['<>', 'status', DocumentStatus::Deleted->value])
            ->orderBy(['created_at' => SORT_DESC, 'id' => SORT_DESC])
            ->all();

        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $items[] = new DocumentListItem(
                id: (int) $row['id'],
                title: $this->title($row['title'] ?? null, $row['original_filename'] ?? null),
                sourceType: DocumentSourceType::from((string) $row['source_type']),
                kind: DocumentKind::from((string) $row['kind']),
                status: DocumentStatus::from((string) $row['status']),
                isEnabled: (bool) (int) $row['is_enabled'],
                sizeBytes: (int) $row['size_bytes'],
                errorMessage: $row['error_message'] === null ? null : (string) $row['error_message'],
                createdAt: DbDateTime::parse((string) $row['created_at']),
                isSourceOverridden: (bool) (int) ($row['is_source_overridden'] ?? 0),
            );
        }

        return $items;
    }

    private function title(mixed $title, mixed $originalFilename): string
    {
        if (is_string($title) && $title !== '') {
            return $title;
        }

        return is_string($originalFilename) ? $originalFilename : '';
    }
}
