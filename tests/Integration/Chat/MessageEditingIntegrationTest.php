<?php

declare(strict_types=1);

namespace App\Tests\Integration\Chat;

use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\MessageRole;
use App\Chat\Domain\NewMessage;
use App\Chat\Infrastructure\DbConversationRepository;
use App\Chat\Infrastructure\DbMessageRepository;
use App\Chat\Infrastructure\DbMessageRevisionRepository;
use App\Shared\Infrastructure\Db\DbDateTime;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Exception\IntegrityException;

use function array_map;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * The editing schema and repository against a real MySQL: the one-active-answer unique index (and the
 * narrow duplicate-key guard around it), superseded rows hidden from live reads but kept for audit, the
 * optimistic lock on question edits, active-history selection, and the revision cascade.
 */
final class MessageEditingIntegrationTest extends Unit
{
    private const SLUG = '__kf_test_msg_editing__';

    private ConnectionInterface $connection;
    private DbMessageRepository $messages;
    private DbMessageRevisionRepository $revisions;
    private int $conversationId;
    private DateTimeImmutable $now;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();
        $this->now = new DateTimeImmutable('2026-05-01 00:00:00', new DateTimeZone('UTC'));
        $kbId = $this->insertKnowledgeBase($this->now);
        $this->conversationId = (new DbConversationRepository($this->connection))->createThread(
            $kbId,
            ChatParticipant::admin(1),
            'Editing',
            $this->now,
        );
        $this->messages = new DbMessageRepository($this->connection);
        $this->revisions = new DbMessageRevisionRepository($this->connection);
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    public function testActiveAnswerUniqueIndexAndNarrowDuplicateGuard(): void
    {
        $userId = $this->messages->add(NewMessage::user($this->conversationId, 'Q1'), $this->at(0));
        $answerId = $this->messages->insertActiveAnswer($this->answer('A1', $userId), $this->at(1));

        // A second active answer for the same question is rejected by ux_messages_active_answer at the DB.
        $threw = false;
        try {
            $this->messages->add($this->answer('A1-dup', $userId), $this->at(2));
        } catch (IntegrityException) {
            $threw = true;
        }
        assertTrue($threw, 'The unique index must reject a second active answer.');

        // insertActiveAnswer catches exactly that race and converges on the existing answer.
        $again = $this->messages->insertActiveAnswer($this->answer('A1-race', $userId), $this->at(3));
        assertSame($answerId, $again);
        assertCount(2, $this->messages->findByConversation($this->conversationId)); // still just Q1 + A1
    }

    public function testSupersededAnswerHiddenFromLiveButKeptForAudit(): void
    {
        $userId = $this->messages->add(NewMessage::user($this->conversationId, 'Q1'), $this->at(0));
        $this->messages->insertActiveAnswer($this->answer('old', $userId), $this->at(1));

        $this->messages->supersedeAnswersFor($userId, $this->at(2));

        // A fresh answer can now take the freed active slot.
        $newAnswerId = $this->messages->insertActiveAnswer($this->answer('new', $userId), $this->at(3));

        $live = array_map(static fn($m) => $m->content, $this->messages->findByConversation($this->conversationId));
        assertSame(['Q1', 'new'], $live);

        $audit = array_map(static fn($m) => $m->content, $this->messages->findAllByConversationIncludingSuperseded($this->conversationId));
        assertSame(['Q1', 'old', 'new'], $audit);

        assertNotSame(0, $newAnswerId);
        assertFalse($this->messages->hasUnansweredLatestUserMessage($this->conversationId));
    }

    public function testOptimisticLockOnUpdateUserContent(): void
    {
        $userId = $this->messages->add(NewMessage::user($this->conversationId, 'Q1'), $this->at(0));

        // Stale edit_count → no update.
        assertFalse($this->messages->updateUserContent($userId, $this->conversationId, 5, 'stale', $this->at(1)));
        assertSame('Q1', $this->messages->findByIdInConversation($userId, $this->conversationId)?->content);

        // Correct edit_count → updates, bumps the counter, stamps edited_at.
        assertTrue($this->messages->updateUserContent($userId, $this->conversationId, 0, 'Q1 edited', $this->at(2)));
        $updated = $this->messages->findByIdInConversation($userId, $this->conversationId);
        assertSame('Q1 edited', $updated?->content);
        assertSame(1, $updated?->editCount);
        assertTrue($updated?->isEdited());

        // The previous expected value no longer matches.
        assertFalse($this->messages->updateUserContent($userId, $this->conversationId, 0, 'again', $this->at(3)));
    }

    public function testFindActiveBeforeExcludesSupersededAndCursor(): void
    {
        $u1 = $this->messages->add(NewMessage::user($this->conversationId, 'Q1'), $this->at(0));
        $this->messages->insertActiveAnswer($this->answer('A1', $u1), $this->at(1));
        $u2 = $this->messages->add(NewMessage::user($this->conversationId, 'Q2'), $this->at(2));
        $this->messages->insertActiveAnswer($this->answer('A2', $u2), $this->at(3));

        // Simulate editing Q2: supersede A2, then look at the history a regeneration would see.
        $this->messages->supersedeAnswersFor($u2, $this->at(4));

        $history = array_map(static fn($m) => $m->content, $this->messages->findActiveBefore($this->conversationId, $u2));
        assertSame(['Q1', 'A1'], $history);
    }

    public function testRevisionAuditTrailIsOrderedAndCascades(): void
    {
        $userId = $this->messages->add(NewMessage::user($this->conversationId, 'Q1'), $this->at(0));
        $this->revisions->add($userId, 1, 'v0', ChatParticipant::admin(1), $this->at(1));
        $this->revisions->add($userId, 2, 'v1', ChatParticipant::agent(3), $this->at(2));

        $found = $this->revisions->findByMessage($userId);
        assertSame([1, 2], array_map(static fn($r) => $r->revisionNumber, $found));
        assertSame(['v0', 'v1'], array_map(static fn($r) => $r->content, $found));
        assertSame('agent', $found[1]->editedByType->value);
        assertSame(3, $found[1]->editedById);

        // Deleting the message cascades its revisions (fk_message_revisions_message ON DELETE CASCADE).
        $this->connection->createCommand()->delete('{{%messages}}', ['id' => $userId])->execute();
        assertCount(0, $this->revisions->findByMessage($userId));
    }

    private function answer(string $text, int $replyToMessageId): NewMessage
    {
        return new NewMessage(
            conversationId: $this->conversationId,
            role: MessageRole::Assistant,
            content: $text,
            replyToMessageId: $replyToMessageId,
        );
    }

    private function at(int $seconds): DateTimeImmutable
    {
        return $this->now->modify('+' . $seconds . ' seconds');
    }

    private function insertKnowledgeBase(DateTimeImmutable $now): int
    {
        $ts = DbDateTime::format($now);
        $this->connection->createCommand()->insert('{{%knowledge_bases}}', [
            'name' => 'Editing KB',
            'slug' => self::SLUG,
            'vector_store_status' => 'ready',
            'status' => 'active',
            'created_at' => $ts,
            'updated_at' => $ts,
        ])->execute();

        return (int) $this->connection->getLastInsertId();
    }

    private function cleanup(): void
    {
        IntegrationDb::cleanup($this->connection ?? IntegrationDb::connectOrSkip(), '{{%knowledge_bases}}', ['slug' => self::SLUG]);
    }
}
