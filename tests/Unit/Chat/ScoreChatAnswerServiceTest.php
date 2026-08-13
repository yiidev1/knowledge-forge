<?php

declare(strict_types=1);

namespace App\Tests\Unit\Chat;

use App\Chat\Application\ScoreChatAnswerService;
use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\Exception\AnswerScoreInvalid;
use App\Chat\Domain\MessageRole;
use App\Chat\Domain\NewMessage;
use App\KnowledgeBase\Domain\KnowledgeBase;
use App\KnowledgeBase\Domain\KnowledgeBaseStatus;
use App\KnowledgeBase\Domain\VectorStoreStatus;
use App\Shared\Domain\Exception\NotFoundException;
use App\Tests\Support\Fake\Chat\InMemoryChatAnswerScoreRepository;
use App\Tests\Support\Fake\Chat\InMemoryConversationRepository;
use App\Tests\Support\Fake\Chat\InMemoryMessageRepository;
use App\Tests\Support\MutableClock;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;

use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;
use function mb_strlen;
use function str_repeat;

/**
 * The rules of rating an answer, minus HTTP and the database.
 *
 * Two properties matter most and are asserted from several angles: a score can only ever reach an answer in
 * the rater's own thread, and a rating is feedback — it never mutates the answer, and declining to rate is
 * never recorded as a zero.
 */
final class ScoreChatAnswerServiceTest extends Unit
{
    private const KB = 7;
    private const KB_OTHER = 8;

    private InMemoryConversationRepository $conversations;
    private InMemoryMessageRepository $messages;
    private InMemoryChatAnswerScoreRepository $scores;
    private MutableClock $clock;

    protected function _before(): void
    {
        $this->conversations = new InMemoryConversationRepository();
        $this->messages = new InMemoryMessageRepository();
        $this->scores = new InMemoryChatAnswerScoreRepository();
        $this->clock = new MutableClock();
    }

    // ---------------------------------------------------------------- happy paths

    public function testAdminScoresOwnAnswer(): void
    {
        $participant = ChatParticipant::admin(1);
        [$conversationId, $answerId] = $this->seedAnsweredThread($participant);

        $this->service()->score($this->knowledgeBase(), $participant, $conversationId, $answerId, '8');

        $state = $this->scores->findForMessage($answerId, $participant);
        assertNotNull($state);
        assertSame(8, $state->score);
        assertTrue($state->isRated());
    }

    public function testAgentScoresOwnAnswer(): void
    {
        $participant = ChatParticipant::agent(42);
        [$conversationId, $answerId] = $this->seedAnsweredThread($participant);

        $this->service()->score($this->knowledgeBase(), $participant, $conversationId, $answerId, '3');

        assertSame(3, $this->scores->findForMessage($answerId, $participant)?->score);
    }

    public function testBoundaryScoresAreAccepted(): void
    {
        foreach ([1, 10] as $value) {
            $participant = ChatParticipant::admin(1);
            $this->_before();
            [$conversationId, $answerId] = $this->seedAnsweredThread($participant);

            $this->service()->score($this->knowledgeBase(), $participant, $conversationId, $answerId, (string) $value);

            assertSame($value, $this->scores->findForMessage($answerId, $participant)?->score);
        }
    }

    public function testSavingTwiceUpdatesTheSameRating(): void
    {
        $participant = ChatParticipant::admin(1);
        [$conversationId, $answerId] = $this->seedAnsweredThread($participant);

        $this->service()->score($this->knowledgeBase(), $participant, $conversationId, $answerId, '4');
        $this->service()->score($this->knowledgeBase(), $participant, $conversationId, $answerId, '9');

        assertSame(9, $this->scores->findForMessage($answerId, $participant)?->score);
        // Two writes, one row — the unique key is what makes "Change" an update rather than a duplicate.
        assertSame(2, count($this->scores->writes));
        assertSame(1, $this->scores->count());
    }

    // ---------------------------------------------------------------- validation

    /**
     * A plain `(int)` cast would turn "8abc" and "8.5" into 8, silently recording a score nobody chose.
     */
    public function testNonIntegerAndOutOfRangeScoresAreRejected(): void
    {
        $participant = ChatParticipant::admin(1);
        [$conversationId, $answerId] = $this->seedAnsweredThread($participant);

        foreach (['0', '11', '8abc', '8.5', '', ' ', 'abc', '-1', 0, 11, -3] as $invalid) {
            $threw = false;
            try {
                $this->service()->score($this->knowledgeBase(), $participant, $conversationId, $answerId, $invalid);
            } catch (AnswerScoreInvalid) {
                $threw = true;
            }

            assertTrue($threw, 'Expected rejection for: ' . var_export($invalid, true));
        }

        // Nothing was written by any of the rejected attempts.
        assertNull($this->scores->findForMessage($answerId, $participant));
    }

    // ---------------------------------------------------------------- authorization

    public function testAnotherAdminCannotScore(): void
    {
        $owner = ChatParticipant::admin(1);
        [$conversationId, $answerId] = $this->seedAnsweredThread($owner);

        $this->expectException(NotFoundException::class);
        $this->service()->score($this->knowledgeBase(), ChatParticipant::admin(2), $conversationId, $answerId, '8');
    }

    public function testAnotherAgentCannotScore(): void
    {
        $owner = ChatParticipant::agent(1);
        [$conversationId, $answerId] = $this->seedAnsweredThread($owner);

        $this->expectException(NotFoundException::class);
        $this->service()->score($this->knowledgeBase(), ChatParticipant::agent(2), $conversationId, $answerId, '8');
    }

    /**
     * The discriminator earns its keep: admin #1 and agent #1 are different owners.
     */
    public function testAgentWithSameNumericIdCannotScoreAdminAnswer(): void
    {
        $owner = ChatParticipant::admin(1);
        [$conversationId, $answerId] = $this->seedAnsweredThread($owner);

        $this->expectException(NotFoundException::class);
        $this->service()->score($this->knowledgeBase(), ChatParticipant::agent(1), $conversationId, $answerId, '8');
    }

    public function testConversationFromAnotherKnowledgeBaseIsNotFound(): void
    {
        $participant = ChatParticipant::admin(1);
        [$conversationId, $answerId] = $this->seedAnsweredThread($participant);

        $this->expectException(NotFoundException::class);
        $this->service()->score($this->otherKnowledgeBase(), $participant, $conversationId, $answerId, '8');
    }

    public function testForgedMessageIdIsNotFound(): void
    {
        $participant = ChatParticipant::admin(1);
        [$conversationId] = $this->seedAnsweredThread($participant);

        $this->expectException(NotFoundException::class);
        $this->service()->score($this->knowledgeBase(), $participant, $conversationId, 987654, '8');
    }

    public function testUserQuestionCannotBeScored(): void
    {
        $participant = ChatParticipant::admin(1);
        [$conversationId, , $questionId] = $this->seedAnsweredThread($participant);

        $this->expectException(NotFoundException::class);
        $this->service()->score($this->knowledgeBase(), $participant, $conversationId, $questionId, '8');
    }

    public function testSupersededAnswerCannotBeScored(): void
    {
        $participant = ChatParticipant::admin(1);
        [$conversationId, $answerId, $questionId] = $this->seedAnsweredThread($participant);

        // An edit supersedes the answer; its scoring control disappears with it.
        $this->messages->supersedeAnswersFor($questionId, $this->clock->now());

        $this->expectException(NotFoundException::class);
        $this->service()->score($this->knowledgeBase(), $participant, $conversationId, $answerId, '8');
    }

    // ---------------------------------------------------------------- the optional low-score note

    /**
     * A note explains a bad rating, so it is accepted exactly across the red band (1–3) and nowhere else.
     */
    public function testLowScoresCanCarryAnOptionalComment(): void
    {
        foreach ([1, 2, 3] as $index => $score) {
            $participant = ChatParticipant::admin(100 + $index);
            [$conversationId, $answerId] = $this->seedAnsweredThread($participant);

            $this->service()->score(
                $this->knowledgeBase(),
                $participant,
                $conversationId,
                $answerId,
                (string) $score,
                '  It quoted the wrong store.  ',
            );

            $state = $this->scores->findForMessage($answerId, $participant);
            assertNotNull($state);
            assertSame($score, $state->score);
            // Trimmed on the way in, so trailing whitespace never becomes part of the note.
            assertSame('It quoted the wrong store.', $state->feedbackComment);
            assertTrue($state->hasComment());
        }
    }

    public function testALowScoreSavesFineWithNoComment(): void
    {
        $participant = ChatParticipant::admin(1);
        [$conversationId, $answerId] = $this->seedAnsweredThread($participant);

        $this->service()->score($this->knowledgeBase(), $participant, $conversationId, $answerId, '2');

        $state = $this->scores->findForMessage($answerId, $participant);
        assertNotNull($state);
        assertSame(2, $state->score);
        assertNull($state->feedbackComment);
        assertTrue($state->isRated());
    }

    public function testBlankAndWhitespaceOnlyCommentsAreStoredAsNull(): void
    {
        $participant = ChatParticipant::admin(1);
        [$conversationId, $answerId] = $this->seedAnsweredThread($participant);

        $this->service()->score($this->knowledgeBase(), $participant, $conversationId, $answerId, '1', "   \n  ");

        assertNull($this->scores->findForMessage($answerId, $participant)?->feedbackComment);
    }

    /**
     * Above the red band the note is dropped rather than rejected. The browser hides the field, but a stale
     * page or a crafted post can still send one, and criticism must never stay attached to a rating that no
     * longer makes it.
     */
    public function testScoresAboveThreeNeverStoreAComment(): void
    {
        foreach ([4, 6, 8, 10] as $index => $score) {
            $participant = ChatParticipant::admin(200 + $index);
            [$conversationId, $answerId] = $this->seedAnsweredThread($participant);

            $this->service()->score(
                $this->knowledgeBase(),
                $participant,
                $conversationId,
                $answerId,
                (string) $score,
                'This should not be kept.',
            );

            $state = $this->scores->findForMessage($answerId, $participant);
            assertNotNull($state);
            assertSame($score, $state->score);
            assertNull($state->feedbackComment);
        }
    }

    public function testRaisingALowScoreClearsItsComment(): void
    {
        $participant = ChatParticipant::admin(1);
        [$conversationId, $answerId] = $this->seedAnsweredThread($participant);

        $this->service()->score($this->knowledgeBase(), $participant, $conversationId, $answerId, '2', 'Wrong price.');
        assertSame('Wrong price.', $this->scores->findForMessage($answerId, $participant)?->feedbackComment);

        // Re-rated as good, with no note this time: the old complaint must not survive.
        $this->service()->score($this->knowledgeBase(), $participant, $conversationId, $answerId, '8');

        $state = $this->scores->findForMessage($answerId, $participant);
        assertNotNull($state);
        assertSame(8, $state->score);
        assertNull($state->feedbackComment);
        assertSame(1, $this->scores->count());
    }

    public function testLoweringAGoodScoreAcceptsANewComment(): void
    {
        $participant = ChatParticipant::admin(1);
        [$conversationId, $answerId] = $this->seedAnsweredThread($participant);

        $this->service()->score($this->knowledgeBase(), $participant, $conversationId, $answerId, '8');
        $this->service()->score($this->knowledgeBase(), $participant, $conversationId, $answerId, '2', 'Missed the rule.');

        $state = $this->scores->findForMessage($answerId, $participant);
        assertNotNull($state);
        assertSame(2, $state->score);
        assertSame('Missed the rule.', $state->feedbackComment);
        assertSame(1, $this->scores->count());
    }

    public function testACommentAtTheLengthLimitIsAcceptedAndOneCharacterMoreIsRejected(): void
    {
        $participant = ChatParticipant::admin(1);
        [$conversationId, $answerId] = $this->seedAnsweredThread($participant);

        $this->service()->score(
            $this->knowledgeBase(),
            $participant,
            $conversationId,
            $answerId,
            '1',
            str_repeat('a', 500),
        );
        assertSame(500, mb_strlen((string) $this->scores->findForMessage($answerId, $participant)?->feedbackComment));

        $this->expectException(AnswerScoreInvalid::class);
        $this->service()->score(
            $this->knowledgeBase(),
            $participant,
            $conversationId,
            $answerId,
            '1',
            str_repeat('a', 501),
        );
    }

    /**
     * Length is counted in characters, not bytes, so a multi-byte note is not rejected early.
     */
    public function testCommentLengthIsCountedInCharacters(): void
    {
        $participant = ChatParticipant::admin(1);
        [$conversationId, $answerId] = $this->seedAnsweredThread($participant);

        $this->service()->score(
            $this->knowledgeBase(),
            $participant,
            $conversationId,
            $answerId,
            '3',
            str_repeat('é', 500),
        );

        assertSame(500, mb_strlen((string) $this->scores->findForMessage($answerId, $participant)?->feedbackComment));
    }

    public function testANonStringCommentIsIgnoredRatherThanCoerced(): void
    {
        $participant = ChatParticipant::admin(1);
        [$conversationId, $answerId] = $this->seedAnsweredThread($participant);

        $this->service()->score($this->knowledgeBase(), $participant, $conversationId, $answerId, '2', ['array']);

        assertNull($this->scores->findForMessage($answerId, $participant)?->feedbackComment);
    }

    /**
     * A note belongs to the rating that carries it, and ratings are per participant.
     */
    public function testACommentIsStoredOnlyOnTheRatersOwnRow(): void
    {
        $owner = ChatParticipant::admin(1);
        [$conversationId, $answerId] = $this->seedAnsweredThread($owner);

        $this->service()->score($this->knowledgeBase(), $owner, $conversationId, $answerId, '2', 'Not accurate.');

        assertNull($this->scores->findForMessage($answerId, ChatParticipant::admin(2)));
        assertNull($this->scores->findForMessage($answerId, ChatParticipant::agent(1)));
    }

    // ---------------------------------------------------------------- dismissal

    public function testDismissRecordsNoScore(): void
    {
        $participant = ChatParticipant::admin(1);
        [$conversationId, $answerId] = $this->seedAnsweredThread($participant);

        $this->service()->dismiss($this->knowledgeBase(), $participant, $conversationId, $answerId);

        $state = $this->scores->findForMessage($answerId, $participant);
        assertNotNull($state);
        // The whole point: declining is not a zero, and must never be averaged in as one.
        assertNull($state->score);
        assertTrue($state->isDismissed());
    }

    public function testScoringAfterDismissClearsTheDismissal(): void
    {
        $participant = ChatParticipant::admin(1);
        [$conversationId, $answerId] = $this->seedAnsweredThread($participant);

        $this->service()->dismiss($this->knowledgeBase(), $participant, $conversationId, $answerId);
        $this->service()->score($this->knowledgeBase(), $participant, $conversationId, $answerId, '7');

        $state = $this->scores->findForMessage($answerId, $participant);
        assertNotNull($state);
        assertSame(7, $state->score);
        assertNull($state->dismissedAt);
        assertTrue($state->isRated());
        assertSame(1, $this->scores->count());
    }

    /**
     * The rated UI only offers "Change", so a dismissal here is a stale page or a forged post. It must not
     * quietly throw away a score the participant deliberately gave.
     */
    public function testDismissingAnAlreadyRatedAnswerIsRejectedAndKeepsTheScore(): void
    {
        $participant = ChatParticipant::admin(1);
        [$conversationId, $answerId] = $this->seedAnsweredThread($participant);

        $this->service()->score($this->knowledgeBase(), $participant, $conversationId, $answerId, '9');

        $threw = false;
        try {
            $this->service()->dismiss($this->knowledgeBase(), $participant, $conversationId, $answerId);
        } catch (AnswerScoreInvalid) {
            $threw = true;
        }

        assertTrue($threw);
        assertSame(9, $this->scores->findForMessage($answerId, $participant)?->score);
    }

    public function testAnotherParticipantCannotDismiss(): void
    {
        $owner = ChatParticipant::admin(1);
        [$conversationId, $answerId] = $this->seedAnsweredThread($owner);

        $this->expectException(NotFoundException::class);
        $this->service()->dismiss($this->knowledgeBase(), ChatParticipant::admin(2), $conversationId, $answerId);
    }

    /**
     * Scores are per participant: two people rating the same answer keep separate rows.
     */
    public function testScoresAreIsolatedPerParticipant(): void
    {
        $owner = ChatParticipant::admin(1);
        [$conversationId, $answerId] = $this->seedAnsweredThread($owner);
        $this->service()->score($this->knowledgeBase(), $owner, $conversationId, $answerId, '8');

        assertNull($this->scores->findForMessage($answerId, ChatParticipant::admin(2)));
        assertNull($this->scores->findForMessage($answerId, ChatParticipant::agent(1)));
    }

    // ---------------------------------------------------------------- helpers

    private function service(): ScoreChatAnswerService
    {
        return new ScoreChatAnswerService(
            $this->conversations,
            $this->messages,
            $this->scores,
            $this->clock,
        );
    }

    /**
     * @return array{0: int, 1: int, 2: int} conversation id, answer id, question id
     */
    private function seedAnsweredThread(ChatParticipant $participant): array
    {
        $now = $this->clock->now();
        $conversationId = $this->conversations->createThread(self::KB, $participant, 'Thread', $now);
        $questionId = $this->messages->add(NewMessage::user($conversationId, 'A question?'), $now);
        $answerId = $this->messages->insertActiveAnswer(
            new NewMessage(
                conversationId: $conversationId,
                role: MessageRole::Assistant,
                content: 'An answer.',
                replyToMessageId: $questionId,
            ),
            $now,
        );

        return [$conversationId, $answerId, $questionId];
    }

    private function knowledgeBase(int $id = self::KB): KnowledgeBase
    {
        $at = new DateTimeImmutable('2026-05-01 00:00:00', new DateTimeZone('UTC'));

        return new KnowledgeBase(
            id: $id,
            name: 'KB ' . $id,
            slug: 'kb-' . $id,
            description: null,
            systemInstructions: null,
            openaiVectorStoreId: 'vs_' . $id,
            vectorStoreStatus: VectorStoreStatus::Ready,
            vectorStoreError: null,
            status: KnowledgeBaseStatus::Active,
            createdAt: $at,
            updatedAt: $at,
        );
    }

    private function otherKnowledgeBase(): KnowledgeBase
    {
        return $this->knowledgeBase(self::KB_OTHER);
    }
}
