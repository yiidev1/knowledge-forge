<?php

declare(strict_types=1);

namespace App\Tests\Integration\Chat;

use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\MessageRole;
use App\Chat\Domain\NewMessage;
use App\Chat\Infrastructure\DbChatAnswerScoreRepository;
use App\Chat\Infrastructure\DbConversationRepository;
use App\Chat\Infrastructure\DbMessageRepository;
use App\Shared\Infrastructure\Db\DbDateTime;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * The scoring table's guarantees against real MySQL — the parts a fake cannot prove: the unique key that
 * makes a re-score an update, the CHECK constraints that refuse an out-of-range score even if the service
 * were bypassed, and the FK cascade that stops scores outliving their answer.
 */
final class ChatAnswerScoreIntegrationTest extends Unit
{
    private const SLUG = '__kf_test_answer_scores__';

    private ConnectionInterface $connection;
    private DbChatAnswerScoreRepository $scores;
    private DbConversationRepository $conversations;
    private DbMessageRepository $messages;
    private DateTimeImmutable $now;
    private int $knowledgeBaseId;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->scores = new DbChatAnswerScoreRepository($this->connection);
        $this->conversations = new DbConversationRepository($this->connection);
        $this->messages = new DbMessageRepository($this->connection);
        $this->now = new DateTimeImmutable('2026-05-01 10:00:00', new DateTimeZone('UTC'));

        $this->cleanup();
        $this->knowledgeBaseId = $this->insertKnowledgeBase();
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    public function testScoringTwiceUpdatesOneRow(): void
    {
        $participant = ChatParticipant::admin(90001);
        $answerId = $this->seedAnswer($participant);

        $this->scores->saveScore($answerId, $participant, 4, null, $this->now);
        $this->scores->saveScore($answerId, $participant, 9, null, $this->now->modify('+1 minute'));

        assertSame(9, $this->scores->findForMessage($answerId, $participant)?->score);
        assertSame(1, $this->countRows($answerId));
    }

    public function testDismissalStoresNoScoreAndScoringLaterClearsIt(): void
    {
        $participant = ChatParticipant::admin(90002);
        $answerId = $this->seedAnswer($participant);

        $this->scores->saveDismissal($answerId, $participant, $this->now);
        $dismissed = $this->scores->findForMessage($answerId, $participant);
        assertNotNull($dismissed);
        assertNull($dismissed->score);
        assertTrue($dismissed->isDismissed());

        $this->scores->saveScore($answerId, $participant, 7, null, $this->now->modify('+2 minutes'));
        $rated = $this->scores->findForMessage($answerId, $participant);
        assertNotNull($rated);
        assertSame(7, $rated->score);
        assertNull($rated->dismissedAt);
        assertSame(1, $this->countRows($answerId));
    }

    /**
     * A dismissal must never discard a score, even at the SQL layer — the statement only touches
     * `dismissed_at`, so a score written first survives it.
     */
    public function testDismissalDoesNotClearAnExistingScore(): void
    {
        $participant = ChatParticipant::admin(90003);
        $answerId = $this->seedAnswer($participant);

        $this->scores->saveScore($answerId, $participant, 6, null, $this->now);
        $this->scores->saveDismissal($answerId, $participant, $this->now->modify('+1 minute'));

        assertSame(6, $this->scores->findForMessage($answerId, $participant)?->score);
    }

    public function testTwoParticipantsScoreTheSameAnswerIndependently(): void
    {
        $admin = ChatParticipant::admin(90004);
        $answerId = $this->seedAnswer($admin);
        // Same numeric id, different realm — the discriminator keeps the rows apart.
        $agent = ChatParticipant::agent(90004);

        $this->scores->saveScore($answerId, $admin, 2, null, $this->now);
        $this->scores->saveScore($answerId, $agent, 10, null, $this->now);

        assertSame(2, $this->scores->findForMessage($answerId, $admin)?->score);
        assertSame(10, $this->scores->findForMessage($answerId, $agent)?->score);
        assertSame(2, $this->countRows($answerId));
    }

    public function testDatabaseRefusesOutOfRangeScores(): void
    {
        $participant = ChatParticipant::admin(90005);
        $answerId = $this->seedAnswer($participant);

        foreach ([0, 11, 255] as $invalid) {
            $rejected = false;
            try {
                $this->connection->createCommand()->insert('{{%chat_answer_scores}}', [
                    'message_id' => $answerId,
                    'participant_type' => 'admin',
                    'participant_id' => 90005 + $invalid,
                    'score' => $invalid,
                    'dismissed_at' => null,
                    'created_at' => DbDateTime::format($this->now),
                    'updated_at' => DbDateTime::format($this->now),
                ])->execute();
            } catch (Throwable) {
                $rejected = true;
            }

            assertTrue($rejected, 'Database accepted an out-of-range score: ' . $invalid);
        }
    }

    /**
     * `chk_chat_answer_scores_meaningful`: a row must mean something — rated or dismissed, never neither.
     */
    public function testDatabaseRefusesARowThatIsNeitherScoredNorDismissed(): void
    {
        $participant = ChatParticipant::admin(90006);
        $answerId = $this->seedAnswer($participant);

        $rejected = false;
        try {
            $this->connection->createCommand()->insert('{{%chat_answer_scores}}', [
                'message_id' => $answerId,
                'participant_type' => 'admin',
                'participant_id' => 90006,
                'score' => null,
                'dismissed_at' => null,
                'created_at' => DbDateTime::format($this->now),
                'updated_at' => DbDateTime::format($this->now),
            ])->execute();
        } catch (Throwable) {
            $rejected = true;
        }

        assertTrue($rejected);
    }

    public function testScoresAreRemovedWithTheirAnswer(): void
    {
        $participant = ChatParticipant::admin(90007);
        $answerId = $this->seedAnswer($participant);
        $this->scores->saveScore($answerId, $participant, 5, null, $this->now);

        $this->connection->createCommand()->delete('{{%messages}}', ['id' => $answerId])->execute();

        assertSame(0, $this->countRows($answerId));
    }

    public function testBulkLoadReturnsOneEntryPerScoredMessage(): void
    {
        $participant = ChatParticipant::admin(90008);
        $first = $this->seedAnswer($participant);
        $second = $this->seedAnswer($participant, 'Second question?');

        $this->scores->saveScore($first, $participant, 3, null, $this->now);
        $this->scores->saveDismissal($second, $participant, $this->now);

        $states = $this->scores->findForMessages([$first, $second, 987654321], $participant);

        assertCount(2, $states);
        assertSame(3, $states[$first]->score);
        assertTrue($states[$second]->isDismissed());
    }

    public function testBulkLoadIsEmptyForAParticipantWithNoFeedback(): void
    {
        $owner = ChatParticipant::admin(90009);
        $answerId = $this->seedAnswer($owner);
        $this->scores->saveScore($answerId, $owner, 8, null, $this->now);

        assertSame([], $this->scores->findForMessages([$answerId], ChatParticipant::admin(90010)));
    }

    /**
     * `chk_chat_answer_scores_comment_low_only`: a note may only accompany a red-band score. The service
     * already drops one above 3, so reaching this needs the service to be bypassed — which is exactly what
     * the constraint is for.
     */
    public function testDatabaseRefusesACommentOnAScoreAboveThree(): void
    {
        $participant = ChatParticipant::admin(90011);
        $answerId = $this->seedAnswer($participant);

        $rejected = false;
        try {
            $this->connection->createCommand()->insert('{{%chat_answer_scores}}', [
                'message_id' => $answerId,
                'participant_type' => 'admin',
                'participant_id' => 90011,
                'score' => 7,
                'feedback_comment' => 'Should not be storable.',
                'dismissed_at' => null,
                'created_at' => DbDateTime::format($this->now),
                'updated_at' => DbDateTime::format($this->now),
            ])->execute();
        } catch (Throwable) {
            $rejected = true;
        }

        assertTrue($rejected);
    }

    public function testACommentSurvivesARoundTripAndIsClearedByRaisingTheScore(): void
    {
        $participant = ChatParticipant::admin(90012);
        $answerId = $this->seedAnswer($participant);

        $this->scores->saveScore($answerId, $participant, 2, 'It used the wrong store hours.', $this->now);
        $low = $this->scores->findForMessage($answerId, $participant);
        assertNotNull($low);
        assertSame('It used the wrong store hours.', $low->feedbackComment);
        assertTrue($low->hasComment());

        // The upsert overwrites rather than merges, so the note goes when the rating stops being a complaint.
        $this->scores->saveScore($answerId, $participant, 9, null, $this->now->modify('+1 minute'));
        $raised = $this->scores->findForMessage($answerId, $participant);
        assertNotNull($raised);
        assertSame(9, $raised->score);
        assertNull($raised->feedbackComment);
        assertSame(1, $this->countRows($answerId));
    }

    // ---------------------------------------------------------------- helpers

    private function seedAnswer(ChatParticipant $participant, string $question = 'A question?'): int
    {
        $conversationId = $this->conversations->findThread($this->knowledgeBaseId, $participant)?->id
            ?? $this->conversations->createThread($this->knowledgeBaseId, $participant, 'Thread', $this->now);

        $questionId = $this->messages->add(NewMessage::user($conversationId, $question), $this->now);

        return $this->messages->insertActiveAnswer(
            new NewMessage(
                conversationId: $conversationId,
                role: MessageRole::Assistant,
                content: 'An answer.',
                replyToMessageId: $questionId,
            ),
            $this->now,
        );
    }

    private function countRows(int $messageId): int
    {
        return (int) $this->connection->createQuery()
            ->from('{{%chat_answer_scores}}')
            ->where(['message_id' => $messageId])
            ->count('*');
    }

    private function insertKnowledgeBase(): int
    {
        $this->connection->createCommand()->insert('{{%knowledge_bases}}', [
            'name' => 'Answer score test',
            'slug' => self::SLUG,
            'description' => null,
            'system_instructions' => null,
            'openai_vector_store_id' => 'vs_' . self::SLUG,
            'vector_store_status' => 'ready',
            'status' => 'active',
            'created_at' => DbDateTime::format($this->now),
            'updated_at' => DbDateTime::format($this->now),
        ])->execute();

        return (int) $this->connection->getLastInsertId();
    }

    /**
     * Deletes only this test's knowledge base; conversations, messages and their scores cascade from it.
     */
    private function cleanup(): void
    {
        IntegrationDb::cleanup($this->connection, '{{%knowledge_bases}}', ['slug' => self::SLUG]);
    }
}
