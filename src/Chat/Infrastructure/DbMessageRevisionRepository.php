<?php

declare(strict_types=1);

namespace App\Chat\Infrastructure;

use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\MessageRevision;
use App\Chat\Domain\MessageRevisionRepositoryInterface;
use App\Chat\Domain\ParticipantType;
use App\Shared\Infrastructure\Db\DbDateTime;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\QueryInterface;

use function is_array;

/**
 * MySQL-backed audit trail of edited questions. Append-only; the (message_id, revision_number) unique key
 * keeps numbering gap-free and race-free.
 */
final readonly class DbMessageRevisionRepository implements MessageRevisionRepositoryInterface
{
    private const TABLE = '{{%message_revisions}}';

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function add(
        int $messageId,
        int $revisionNumber,
        string $priorContent,
        ChatParticipant $editor,
        DateTimeImmutable $now,
    ): int {
        $this->connection->createCommand()->insert(self::TABLE, [
            'message_id' => $messageId,
            'revision_number' => $revisionNumber,
            'content' => $priorContent,
            'edited_by_type' => $editor->type->value,
            'edited_by_id' => $editor->id,
            'created_at' => DbDateTime::format($now),
        ])->execute();

        return (int) $this->connection->getLastInsertId();
    }

    public function findByMessage(int $messageId): array
    {
        $rows = $this->query()
            ->where(['message_id' => $messageId])
            ->orderBy(['revision_number' => SORT_ASC])
            ->all();

        $result = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $result[] = new MessageRevision(
                    id: (int) $row['id'],
                    messageId: (int) $row['message_id'],
                    revisionNumber: (int) $row['revision_number'],
                    content: (string) $row['content'],
                    editedByType: ParticipantType::from((string) $row['edited_by_type']),
                    editedById: (int) $row['edited_by_id'],
                    createdAt: DbDateTime::parse((string) $row['created_at']),
                );
            }
        }

        return $result;
    }

    private function query(): QueryInterface
    {
        return $this->connection->createQuery()->from(self::TABLE);
    }
}
