<?php

declare(strict_types=1);

namespace App\Agent\Infrastructure;

use App\Agent\Domain\AgentConversationRepositoryInterface;
use App\Chat\Domain\Conversation;
use App\Shared\Infrastructure\Db\DbDateTime;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\QueryInterface;

use function is_array;

/**
 * MySQL-backed agent conversation repository. Writes bind the row to the agent; reads always filter by
 * agent id, giving each agent a private view of their own conversations.
 */
final readonly class DbAgentConversationRepository implements AgentConversationRepositoryInterface
{
    private const TABLE = '{{%conversations}}';

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function create(int $knowledgeBaseId, int $agentAdminId, string $title, DateTimeImmutable $now): int
    {
        $formatted = DbDateTime::format($now);

        $this->connection->createCommand()->insert(self::TABLE, [
            'knowledge_base_id' => $knowledgeBaseId,
            'agent_admin_id' => $agentAdminId,
            'title' => $title,
            'last_message_at' => $formatted,
            'created_at' => $formatted,
            'updated_at' => $formatted,
        ])->execute();

        return (int) $this->connection->getLastInsertId();
    }

    public function findForAgentInKnowledgeBase(int $knowledgeBaseId, int $agentAdminId): array
    {
        $rows = $this->query()
            ->where(['knowledge_base_id' => $knowledgeBaseId, 'agent_admin_id' => $agentAdminId])
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

    public function findForAgent(int $conversationId, int $knowledgeBaseId, int $agentAdminId): ?Conversation
    {
        return $this->hydrate(
            $this->query()
                ->where([
                    'id' => $conversationId,
                    'knowledge_base_id' => $knowledgeBaseId,
                    'agent_admin_id' => $agentAdminId,
                ])
                ->limit(1)
                ->one(),
        );
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
