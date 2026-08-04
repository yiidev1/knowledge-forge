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
use Yiisoft\Db\Exception\IntegrityException;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\QueryInterface;

use function array_map;
use function array_reverse;
use function is_array;
use function json_decode;
use function json_encode;
use function str_contains;

use const JSON_THROW_ON_ERROR;

/**
 * MySQL-backed message repository. Ordering is created_at ASC, id ASC for stable merge-safe chronology.
 *
 * Superseded answers (replaced by an edit) are excluded from every live/history read here; only the
 * dedicated audit accessor returns them. The active-answer invariant is enforced by the
 * `ux_messages_active_answer` unique index, whose only expected race is handled in {@see insertActiveAnswer}.
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
            'reply_to_message_id' => $message->replyToMessageId,
            'created_at' => DbDateTime::format($now),
        ])->execute();

        return (int) $this->connection->getLastInsertId();
    }

    public function insertActiveAnswer(NewMessage $answer, DateTimeImmutable $now): int
    {
        try {
            return $this->add($answer, $now);
        } catch (IntegrityException $e) {
            // The ONLY duplicate this may legitimately hit is a concurrent regeneration that already
            // inserted the active answer for the same question. Anything else is a real integrity fault.
            if (!$this->isActiveAnswerDuplicate($e)) {
                throw $e;
            }

            $existing = $answer->replyToMessageId === null
                ? null
                : $this->findActiveAnswerId($answer->replyToMessageId);

            // A duplicate-key error with no active answer to point at is an unexpected integrity state —
            // do not swallow it.
            if ($existing === null) {
                throw $e;
            }

            return $existing;
        }
    }

    public function findByConversation(int $conversationId): array
    {
        $rows = $this->query()
            ->where(['conversation_id' => $conversationId, 'superseded_at' => null])
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
            ->where(['conversation_id' => $conversationId, 'superseded_at' => null])
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
            ->where(['conversation_id' => $conversationId, 'superseded_at' => null])
            ->andWhere(new Expression(
                '([[created_at]] < :cursor_at) OR ([[created_at]] = :cursor_at AND [[id]] < :cursor_id)',
                [':cursor_at' => $createdAt, ':cursor_id' => $cursorId],
            ))
            ->orderBy(['created_at' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit)
            ->all();

        return array_reverse($this->hydrateAll($rows));
    }

    public function findActiveBefore(int $conversationId, int $messageId): array
    {
        $cursor = $this->query()
            ->select(['id', 'created_at'])
            ->where(['id' => $messageId, 'conversation_id' => $conversationId])
            ->limit(1)
            ->one();

        if (!is_array($cursor)) {
            return [];
        }

        $createdAt = (string) $cursor['created_at'];
        $cursorId = (int) $cursor['id'];

        $rows = $this->query()
            ->where(['conversation_id' => $conversationId, 'superseded_at' => null])
            ->andWhere(new Expression(
                '([[created_at]] < :cursor_at) OR ([[created_at]] = :cursor_at AND [[id]] < :cursor_id)',
                [':cursor_at' => $createdAt, ':cursor_id' => $cursorId],
            ))
            ->orderBy(['created_at' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        return $this->hydrateAll($rows);
    }

    public function countByConversation(int $conversationId): int
    {
        return (int) $this->query()
            ->where(['conversation_id' => $conversationId, 'superseded_at' => null])
            ->count('*');
    }

    public function findByIdInConversation(int $messageId, int $conversationId): ?Message
    {
        return $this->hydrate(
            $this->query()
                ->where(['id' => $messageId, 'conversation_id' => $conversationId])
                ->limit(1)
                ->one(),
        );
    }

    public function findLatestUserMessageId(int $conversationId): ?int
    {
        $id = $this->query()
            ->select('id')
            ->where(['conversation_id' => $conversationId, 'role' => MessageRole::User->value])
            ->orderBy(['created_at' => SORT_DESC, 'id' => SORT_DESC])
            ->limit(1)
            ->scalar();

        return $id === false || $id === null ? null : (int) $id;
    }

    public function updateUserContent(
        int $messageId,
        int $conversationId,
        int $expectedEditCount,
        string $content,
        DateTimeImmutable $now,
    ): bool {
        $affected = $this->connection->createCommand()->update(
            self::TABLE,
            [
                'content' => $content,
                'edited_at' => DbDateTime::format($now),
                'edit_count' => new Expression('[[edit_count]] + 1'),
            ],
            [
                'id' => $messageId,
                'conversation_id' => $conversationId,
                'role' => MessageRole::User->value,
                'edit_count' => $expectedEditCount,
            ],
        )->execute();

        return $affected === 1;
    }

    public function supersedeAnswersFor(int $userMessageId, DateTimeImmutable $now): void
    {
        $this->connection->createCommand()->update(
            self::TABLE,
            ['superseded_at' => DbDateTime::format($now)],
            [
                'role' => MessageRole::Assistant->value,
                'reply_to_message_id' => $userMessageId,
                'superseded_at' => null,
            ],
        )->execute();
    }

    public function hasUnansweredLatestUserMessage(int $conversationId): bool
    {
        $latestUserId = $this->findLatestUserMessageId($conversationId);
        if ($latestUserId === null) {
            return false;
        }

        return $this->findActiveAnswerId($latestUserId) === null;
    }

    public function findAllByConversationIncludingSuperseded(int $conversationId): array
    {
        $rows = $this->query()
            ->where(['conversation_id' => $conversationId])
            ->orderBy(['created_at' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        return $this->hydrateAll($rows);
    }

    /**
     * The id of the active (non-superseded) assistant answer to a question, or null if none exists.
     */
    private function findActiveAnswerId(int $userMessageId): ?int
    {
        $id = $this->query()
            ->select('id')
            ->where([
                'role' => MessageRole::Assistant->value,
                'reply_to_message_id' => $userMessageId,
                'superseded_at' => null,
            ])
            ->limit(1)
            ->scalar();

        return $id === false || $id === null ? null : (int) $id;
    }

    private function isActiveAnswerDuplicate(IntegrityException $e): bool
    {
        $message = $e->getMessage();

        // Match the specific unique index by name AND confirm it is a duplicate-key error (SQLSTATE 23000 /
        // MySQL 1062), so an unrelated constraint failure is never mistaken for the expected race.
        return str_contains($message, 'ux_messages_active_answer')
            && (str_contains($message, '1062') || (string) $e->getCode() === '23000');
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
            replyToMessageId: isset($row['reply_to_message_id']) && $row['reply_to_message_id'] !== null
                ? (int) $row['reply_to_message_id']
                : null,
            supersededAt: DbDateTime::parseNullable(
                isset($row['superseded_at']) && $row['superseded_at'] !== null ? (string) $row['superseded_at'] : null,
            ),
            editedAt: DbDateTime::parseNullable(
                isset($row['edited_at']) && $row['edited_at'] !== null ? (string) $row['edited_at'] : null,
            ),
            editCount: (int) ($row['edit_count'] ?? 0),
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
