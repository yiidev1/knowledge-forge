<?php

declare(strict_types=1);

namespace App\Chat\Infrastructure;

use App\Chat\Domain\Message;
use App\Chat\Domain\MessageRepositoryInterface;
use App\Chat\Domain\MessageRole;
use App\Chat\Domain\NewMessage;
use App\Chat\Domain\ResolvedCitation;
use App\Shared\Infrastructure\Db\DbDateTime;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\QueryInterface;

use function array_map;
use function array_reverse;
use function is_array;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * MySQL-backed message repository. Ordering is created_at ASC, id ASC for stable merge-safe chronology.
 */
final readonly class DbMessageRepository implements MessageRepositoryInterface
{
    private const TABLE = '{{%messages}}';

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function add(NewMessage $message, DateTimeImmutable $now): int
    {
        $citations = array_map(static fn(ResolvedCitation $c): array => $c->toArray(), $message->citations);

        $this->connection->createCommand()->insert(self::TABLE, [
            'conversation_id' => $message->conversationId,
            'role' => $message->role->value,
            'content' => $message->content,
            'citations_json' => $citations === [] ? null : json_encode($citations, JSON_THROW_ON_ERROR),
            'usage_json' => $message->usage === null ? null : json_encode($message->usage, JSON_THROW_ON_ERROR),
            'is_grounded' => $message->isGrounded ? 1 : 0,
            'retrieval_status' => $message->retrievalStatus,
            'openai_response_id' => $message->providerResponseId,
            'model' => $message->model,
            'created_at' => DbDateTime::format($now),
        ])->execute();

        return (int) $this->connection->getLastInsertId();
    }

    public function findByConversation(int $conversationId): array
    {
        $rows = $this->query()
            ->where(['conversation_id' => $conversationId])
            ->orderBy(['created_at' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        return $this->hydrateAll($rows);
    }

    public function findRecentByConversation(int $conversationId, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        $rows = $this->query()
            ->where(['conversation_id' => $conversationId])
            ->orderBy(['created_at' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit)
            ->all();

        return array_reverse($this->hydrateAll($rows));
    }

    public function findBefore(int $conversationId, int $beforeMessageId, int $limit): ?array
    {
        if ($limit < 1) {
            return [];
        }

        $cursor = $this->query()
            ->select(['id', 'created_at'])
            ->where(['id' => $beforeMessageId, 'conversation_id' => $conversationId])
            ->limit(1)
            ->one();

        if (!is_array($cursor)) {
            return null;
        }

        $createdAt = (string) $cursor['created_at'];
        $cursorId = (int) $cursor['id'];

        $rows = $this->query()
            ->where(['conversation_id' => $conversationId])
            ->andWhere(new Expression(
                '([[created_at]] < :cursor_at) OR ([[created_at]] = :cursor_at AND [[id]] < :cursor_id)',
                [':cursor_at' => $createdAt, ':cursor_id' => $cursorId],
            ))
            ->orderBy(['created_at' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit)
            ->all();

        return array_reverse($this->hydrateAll($rows));
    }

    public function countByConversation(int $conversationId): int
    {
        return (int) $this->query()
            ->where(['conversation_id' => $conversationId])
            ->count('*');
    }

    /**
     * @param array<array-key, array|object> $rows
     *
     * @return list<Message>
     */
    private function hydrateAll(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $message = $this->hydrate($row);
            if ($message !== null) {
                $result[] = $message;
            }
        }

        return $result;
    }

    private function query(): QueryInterface
    {
        return $this->connection->createQuery()->from(self::TABLE);
    }

    private function hydrate(array|object|null $row): ?Message
    {
        if (!is_array($row)) {
            return null;
        }

        return new Message(
            id: (int) $row['id'],
            conversationId: (int) $row['conversation_id'],
            role: MessageRole::from((string) $row['role']),
            content: (string) $row['content'],
            citations: $this->decodeCitations($row['citations_json'] ?? null),
            isGrounded: (bool) $row['is_grounded'],
            retrievalStatus: $row['retrieval_status'] === null ? null : (string) $row['retrieval_status'],
            model: $row['model'] === null ? null : (string) $row['model'],
            createdAt: DbDateTime::parse((string) $row['created_at']),
        );
    }

    /**
     * @return list<ResolvedCitation>
     */
    private function decodeCitations(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $citations = [];
        foreach ($decoded as $entry) {
            $citation = ResolvedCitation::fromArray($entry);
            if ($citation !== null) {
                $citations[] = $citation;
            }
        }

        return $citations;
    }
}
