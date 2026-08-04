<?php

declare(strict_types=1);

namespace App\Chat\Infrastructure;

use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\Conversation;
use App\Chat\Domain\ConversationRepositoryInterface;
use App\Shared\Infrastructure\Db\DbDateTime;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\QueryInterface;

use function is_array;

/**
 * MySQL-backed conversation repository. Canonical ownership is participant_type + participant_id.
 * agent_admin_id is kept in sync for compatibility (NULL for admin, agent id for agent).
 */
final readonly class DbConversationRepository implements ConversationRepositoryInterface
{
    private const TABLE = '{{%conversations}}';

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function findThread(int $knowledgeBaseId, ChatParticipant $participant): ?Conversation
    {
        return $this->hydrate(
            $this->query()
                ->where([
                    'knowledge_base_id' => $knowledgeBaseId,
                    'participant_type' => $participant->type->value,
                    'participant_id' => $participant->id,
                ])
                ->limit(1)
                ->one(),
        );
    }

    public function createThread(
        int $knowledgeBaseId,
        ChatParticipant $participant,
        string $title,
        DateTimeImmutable $now,
    ): int {
        $formatted = DbDateTime::format($now);

        $this->connection->createCommand()->insert(self::TABLE, [
            'knowledge_base_id' => $knowledgeBaseId,
            'agent_admin_id' => $participant->legacyAgentAdminId(),
            'participant_type' => $participant->type->value,
            'participant_id' => $participant->id,
            'title' => $title,
            'last_message_at' => $formatted,
            'created_at' => $formatted,
            'updated_at' => $formatted,
        ])->execute();

        return (int) $this->connection->getLastInsertId();
    }

    public function findOwnedThreadById(
        int $conversationId,
        int $knowledgeBaseId,
        ChatParticipant $participant,
    ): ?Conversation {
        return $this->hydrate(
            $this->query()
                ->where([
                    'id' => $conversationId,
                    'knowledge_base_id' => $knowledgeBaseId,
                    'participant_type' => $participant->type->value,
                    'participant_id' => $participant->id,
                ])
                ->limit(1)
                ->one(),
        );
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
