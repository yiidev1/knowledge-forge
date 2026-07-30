<?php

declare(strict_types=1);

namespace App\Chat\Infrastructure;

use App\Chat\Domain\Conversation;
use App\Chat\Domain\ConversationRepositoryInterface;
use App\Shared\Infrastructure\Db\DbDateTime;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\QueryInterface;

use function is_array;

/**
 * MySQL-backed conversation repository. Every read is scoped by knowledge base.
 */
final readonly class DbConversationRepository implements ConversationRepositoryInterface
{
    private const TABLE = '{{%conversations}}';

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function create(int $knowledgeBaseId, string $title, DateTimeImmutable $now): int
    {
        $formatted = DbDateTime::format($now);

        $this->connection->createCommand()->insert(self::TABLE, [
            'knowledge_base_id' => $knowledgeBaseId,
            'title' => $title,
            'last_message_at' => $formatted,
            'created_at' => $formatted,
            'updated_at' => $formatted,
        ])->execute();

        return (int) $this->connection->getLastInsertId();
    }

    public function findByIdForKnowledgeBase(int $conversationId, int $knowledgeBaseId): ?Conversation
    {
        return $this->hydrate(
            $this->query()
                ->where(['id' => $conversationId, 'knowledge_base_id' => $knowledgeBaseId])
                ->limit(1)
                ->one(),
        );
    }

    public function findAllForKnowledgeBase(int $knowledgeBaseId): array
    {
        $rows = $this->query()
            ->where(['knowledge_base_id' => $knowledgeBaseId])
            ->orderBy(['last_message_at' => SORT_DESC, 'id' => SORT_DESC])
            ->all();

        $result = [];
        foreach ($rows as $row) {
            $conversation = $this->hydrate($row);
            if ($conversation !== null) {
                $result[] = $conversation;
            }
        }

        return $result;
    }

    public function touch(int $conversationId, DateTimeImmutable $now): void
    {
        $formatted = DbDateTime::format($now);

        $this->connection->createCommand()->update(
            self::TABLE,
            ['last_message_at' => $formatted, 'updated_at' => $formatted],
            ['id' => $conversationId],
        )->execute();
    }

    private function query(): QueryInterface
    {
        return $this->connection->createQuery()->from(self::TABLE);
    }

    private function hydrate(array|object|null $row): ?Conversation
    {
        if (!is_array($row)) {
            return null;
        }

        return new Conversation(
            id: (int) $row['id'],
            knowledgeBaseId: (int) $row['knowledge_base_id'],
            title: (string) $row['title'],
            lastMessageAt: DbDateTime::parseNullable($row['last_message_at'] === null ? null : (string) $row['last_message_at']),
            createdAt: DbDateTime::parse((string) $row['created_at']),
            updatedAt: DbDateTime::parse((string) $row['updated_at']),
        );
    }
}
