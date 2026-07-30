<?php

declare(strict_types=1);

namespace App\Document\Infrastructure;

use App\Document\Domain\ProcessingEvent;
use App\Document\Domain\ProcessingEventRepositoryInterface;
use App\Shared\Domain\Clock\ClockInterface;
use App\Shared\Infrastructure\Db\DbDateTime;
use Yiisoft\Db\Connection\ConnectionInterface;

use function is_array;
use function is_scalar;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * MySQL-backed processing-event log. Append-only.
 */
final readonly class DbProcessingEventRepository implements ProcessingEventRepositoryInterface
{
    private const TABLE = '{{%document_processing_events}}';

    public function __construct(
        private ConnectionInterface $connection,
        private ClockInterface $clock,
    ) {}

    public function record(int $documentId, string $status, ?string $message = null, array $metadata = []): void
    {
        $this->connection->createCommand()->insert(self::TABLE, [
            'document_id' => $documentId,
            'status' => $status,
            'message' => $message,
            'metadata_json' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => DbDateTime::format($this->clock->now()),
        ])->execute();
    }

    public function findAllForDocument(int $documentId): array
    {
        $rows = $this->connection
            ->createQuery()
            ->from(self::TABLE)
            ->where(['document_id' => $documentId])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        $result = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $result[] = $this->hydrate($row);
            }
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function hydrate(array $row): ProcessingEvent
    {
        return new ProcessingEvent(
            id: (int) $row['id'],
            documentId: (int) $row['document_id'],
            status: (string) $row['status'],
            message: $row['message'] === null ? null : (string) $row['message'],
            metadata: $this->decodeMetadata($row['metadata_json'] ?? null),
            createdAt: DbDateTime::parse((string) $row['created_at']),
        );
    }

    /**
     * @return array<string, scalar|null>
     */
    private function decodeMetadata(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $result = [];
        /** @var mixed $value */
        foreach ($decoded as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $result[(string) $key] = $value;
            }
        }

        return $result;
    }
}
