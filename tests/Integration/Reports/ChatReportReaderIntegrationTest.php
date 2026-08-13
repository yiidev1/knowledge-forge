<?php

declare(strict_types=1);

namespace App\Tests\Integration\Reports;

use App\Reports\Domain\AnswerStatusFilter;
use App\Reports\Domain\ChatReportQuery;
use App\Reports\Domain\ChatTypeFilter;
use App\Reports\Domain\FeedbackFilter;
use App\Reports\Domain\RatingFilter;
use App\Reports\Domain\ReportDateRange;
use App\Reports\Infrastructure\DbChatReportReader;
use App\Rules\Application\EnsureCommonRulesKnowledgeBaseService;
use App\Shared\Application\Time\AppTimeZone;
use App\Shared\Infrastructure\Db\DbDateTime;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * The report's correctness against real MySQL, on a fixture built purely for this test.
 *
 * The things worth proving are the ones a careless query gets wrong: a superseded answer counted twice, a
 * dismissal averaged as a zero, an answer detached from its question at a date boundary, an agent resolved
 * through the wrong column, or a Rule Chat conversation classified by its answer instead of its knowledge
 * base.
 */
final class ChatReportReaderIntegrationTest extends Unit
{
    private const STORE_SLUG = '__kf_test_report_store__';
    private const OTHER_SLUG = '__kf_test_report_store2__';
    private const RULES_SLUG = '__kf_test_report_rules__';

    private const AGENT_A = 970001;
    private const AGENT_B = 970002;

    private ConnectionInterface $connection;
    private DbChatReportReader $reader;
    private AppTimeZone $timeZone;
    private DateTimeImmutable $now;

    private int $storeKb;
    private int $otherKb;
    private int $rulesKb;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->reader = new DbChatReportReader($this->connection);
        $this->timeZone = new AppTimeZone('America/New_York');
        $this->now = new DateTimeImmutable('2026-05-20 12:00:00', new DateTimeZone('UTC'));

        $this->cleanup();
        $this->storeKb = $this->insertKnowledgeBase(self::STORE_SLUG, 'Report Store A', 'store');
        $this->otherKb = $this->insertKnowledgeBase(self::OTHER_SLUG, 'Report Store B', 'store');
        $this->rulesKb = $this->insertKnowledgeBase(
            self::RULES_SLUG,
            'Report Rules',
            EnsureCommonRulesKnowledgeBaseService::PURPOSE,
        );
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    // ---------------------------------------------------------------- pairing and supersession

    public function testQuestionIsPairedWithItsCurrentAnswerOnly(): void
    {
        $conversation = $this->conversation($this->storeKb, self::AGENT_A);
        $question = $this->question($conversation, 'What are the hours?', '2026-05-10 14:00:00');
        // The original answer was superseded by an edit; only the replacement stands.
        $this->answer($conversation, $question, 'Old answer', '2026-05-10 14:00:05', superseded: true);
        $current = $this->answer($conversation, $question, 'Current answer', '2026-05-10 14:00:40');

        $rows = $this->reader->list($this->query())->items;

        assertCount(1, $rows);
        assertSame($current, $rows[0]->answerId);
        assertSame('Current answer', $rows[0]->answer);
        // The superseded answer must not inflate the answer count.
        assertSame(1, $this->reader->summary($this->query())->answers);
        assertSame(40, $rows[0]->responseSeconds);
    }

    public function testUnansweredQuestionIsCountedButShownAsUnanswered(): void
    {
        $conversation = $this->conversation($this->storeKb, self::AGENT_A);
        $this->question($conversation, 'Never answered', '2026-05-10 15:00:00');

        $summary = $this->reader->summary($this->query());
        assertSame(1, $summary->questions);
        assertSame(0, $summary->answers);
        assertSame(1, $summary->unansweredQuestions);
        // An unanswered question is not an unrated answer.
        assertSame(0, $summary->unratedAnswers);

        $rows = $this->reader->list($this->query())->items;
        assertTrue(!$rows[0]->isAnswered());
        assertTrue(!$rows[0]->isUnrated());
    }

    public function testSupersededAnswerIsNotCountedWhenQuestionHasNoReplacement(): void
    {
        $conversation = $this->conversation($this->storeKb, self::AGENT_A);
        $question = $this->question($conversation, 'Edited away', '2026-05-10 16:00:00');
        $this->answer($conversation, $question, 'Superseded only', '2026-05-10 16:00:05', superseded: true);

        $summary = $this->reader->summary($this->query());
        assertSame(1, $summary->questions);
        assertSame(0, $summary->answers);
        assertSame(1, $summary->unansweredQuestions);
    }

    // ---------------------------------------------------------------- date boundaries

    /**
     * The range filters the question. An answer written after midnight must stay attached to the question it
     * answers, otherwise every boundary row silently loses its rating and grounding.
     */
    public function testAnswerJustOutsideTheRangeStaysAttachedToItsQuestion(): void
    {
        $conversation = $this->conversation($this->storeKb, self::AGENT_A);
        // 23:59:50 local on 10 May = 03:59:50 UTC on 11 May; the answer lands after the local day ends.
        $question = $this->question($conversation, 'Late question', '2026-05-11 03:59:50');
        $answer = $this->answer($conversation, $question, 'Answer next day', '2026-05-11 04:00:30');

        $query = $this->query('2026-05-10', '2026-05-10');
        $rows = $this->reader->list($query)->items;

        assertCount(1, $rows);
        assertSame($answer, $rows[0]->answerId);
        assertSame(1, $this->reader->summary($query)->answers);
    }

    public function testLocalDateBoundariesMapToUtc(): void
    {
        $range = ReportDateRange::fromRequest('2026-05-10', '2026-05-10', $this->timeZone, $this->now);

        // May is EDT (UTC-4): local midnight is 04:00 UTC, and the window is half-open on the next day.
        assertSame('2026-05-10 04:00:00', $range->startUtc->format('Y-m-d H:i:s'));
        assertSame('2026-05-11 04:00:00', $range->endUtcExclusive->format('Y-m-d H:i:s'));
    }

    public function testQuestionBeforeLocalMidnightIsExcluded(): void
    {
        $conversation = $this->conversation($this->storeKb, self::AGENT_A);
        // 03:59:59 UTC is still 23:59:59 the previous local day.
        $this->question($conversation, 'Previous local day', '2026-05-10 03:59:59');
        $this->question($conversation, 'First of the day', '2026-05-10 04:00:00');

        assertSame(1, $this->reader->summary($this->query('2026-05-10', '2026-05-10'))->questions);
    }

    // ---------------------------------------------------------------- classification

    public function testRuleChatIsClassifiedByKnowledgeBasePurposeNotAnswerSource(): void
    {
        // A store conversation whose answer carries a rule answer_source — the real-world legacy shape.
        $storeConversation = $this->conversation($this->storeKb, self::AGENT_A);
        $storeQuestion = $this->question($storeConversation, 'Store question', '2026-05-10 14:00:00');
        $this->answer($storeConversation, $storeQuestion, 'Answer', '2026-05-10 14:00:05', answerSource: 'global_rule');

        $ruleConversation = $this->conversation($this->rulesKb, self::AGENT_A);
        $ruleQuestion = $this->question($ruleConversation, 'Rule question', '2026-05-10 14:10:00');
        $this->answer($ruleConversation, $ruleQuestion, 'Rule answer', '2026-05-10 14:10:05', answerSource: 'global_rule');

        $summary = $this->reader->summary($this->query());
        assertSame(1, $summary->storeQuestions, 'answer_source must not decide the surface');
        assertSame(1, $summary->ruleQuestions);

        assertSame(1, $this->reader->summary($this->query(type: ChatTypeFilter::Rule))->questions);
        assertSame(1, $this->reader->summary($this->query(type: ChatTypeFilter::Store))->questions);
    }

    // ---------------------------------------------------------------- ratings

    public function testDismissalIsNotAZeroAndAverageIgnoresIt(): void
    {
        $conversation = $this->conversation($this->storeKb, self::AGENT_A);
        $rated = $this->answeredQuestion($conversation, 'Rated', '2026-05-10 14:00:00');
        $dismissed = $this->answeredQuestion($conversation, 'Dismissed', '2026-05-10 14:05:00');

        $this->score($rated, self::AGENT_A, 8);
        $this->dismiss($dismissed, self::AGENT_A);

        $summary = $this->reader->summary($this->query());
        assertSame(1, $summary->ratedAnswers);
        // The dismissal is unrated, not a zero — an average of 8 and 0 would be 4.
        assertSame(1, $summary->unratedAnswers);
        assertNotNull($summary->averageRating);
        assertSame(8.0, round($summary->averageRating, 4));
    }

    public function testLowScoreCommentIsReported(): void
    {
        $conversation = $this->conversation($this->storeKb, self::AGENT_A);
        $answer = $this->answeredQuestion($conversation, 'Bad answer', '2026-05-10 14:00:00');
        $this->score($answer, self::AGENT_A, 2, 'Completely wrong about delivery.');

        $rows = $this->reader->list($this->query())->items;
        assertSame(2, $rows[0]->score);
        assertSame('Completely wrong about delivery.', $rows[0]->comment);
        assertSame(1, $this->reader->summary($this->query())->lowRatings);
        assertSame(1, $this->reader->summary($this->query())->comments);

        assertCount(1, $this->reader->list($this->query(feedback: FeedbackFilter::WithComment))->items);
        assertCount(0, $this->reader->list($this->query(feedback: FeedbackFilter::WithoutComment))->items);
    }

    public function testAnotherParticipantsRatingIsNotAttributedToTheAgent(): void
    {
        $conversation = $this->conversation($this->storeKb, self::AGENT_A);
        $answer = $this->answeredQuestion($conversation, 'Question', '2026-05-10 14:00:00');

        // An admin rated the same answer, and a different agent id did too. Neither is this agent's rating.
        $this->scoreAs($answer, 'admin', 999001, 10);
        $this->scoreAs($answer, 'agent', self::AGENT_B, 1);

        $rows = $this->reader->list($this->query())->items;
        assertNull($rows[0]->score);
        assertNull($this->reader->summary($this->query())->averageRating);
    }

    public function testRatingBucketsSelectTheRightRows(): void
    {
        $conversation = $this->conversation($this->storeKb, self::AGENT_A);
        foreach ([[2, '14:00:00'], [5, '14:05:00'], [9, '14:10:00']] as [$score, $time]) {
            $answer = $this->answeredQuestion($conversation, 'Q' . $score, '2026-05-10 ' . $time);
            $this->score($answer, self::AGENT_A, $score);
        }
        $unrated = $this->answeredQuestion($conversation, 'Unrated', '2026-05-10 14:15:00');
        assertTrue($unrated > 0);

        assertCount(1, $this->reader->list($this->query(rating: RatingFilter::Low))->items);
        assertCount(1, $this->reader->list($this->query(rating: RatingFilter::Medium))->items);
        assertCount(1, $this->reader->list($this->query(rating: RatingFilter::High))->items);
        assertCount(3, $this->reader->list($this->query(rating: RatingFilter::Rated))->items);
        assertCount(1, $this->reader->list($this->query(rating: RatingFilter::Unrated))->items);
    }

    /** A dismissed answer is unrated; an unanswered question is not an unrated answer. */
    public function testUnratedBucketIncludesDismissalsAndExcludesUnansweredQuestions(): void
    {
        $conversation = $this->conversation($this->storeKb, self::AGENT_A);
        $dismissed = $this->answeredQuestion($conversation, 'Dismissed', '2026-05-10 14:00:00');
        $this->dismiss($dismissed, self::AGENT_A);
        $this->question($conversation, 'Unanswered', '2026-05-10 14:05:00');

        $rows = $this->reader->list($this->query(rating: RatingFilter::Unrated))->items;
        assertCount(1, $rows);
        assertSame('Dismissed', $rows[0]->question);
    }

    // ---------------------------------------------------------------- grounding

    public function testGroundedAndFallbackAreCountedFromIsGrounded(): void
    {
        $conversation = $this->conversation($this->storeKb, self::AGENT_A);
        $this->answeredQuestion($conversation, 'Grounded', '2026-05-10 14:00:00', grounded: true);
        $this->answeredQuestion($conversation, 'Fallback', '2026-05-10 14:05:00', grounded: false);

        $summary = $this->reader->summary($this->query());
        assertSame(1, $summary->groundedAnswers);
        assertSame(1, $summary->fallbackAnswers);

        assertCount(1, $this->reader->list($this->query(status: AnswerStatusFilter::Grounded))->items);
        assertCount(1, $this->reader->list($this->query(status: AnswerStatusFilter::Fallback))->items);
    }

    // ---------------------------------------------------------------- agent identity

    public function testAgentIsResolvedThroughAdminIdNotThePrimaryKey(): void
    {
        $this->insertAgentMirror(self::AGENT_A, 'a.smith', 'Anna', 'Smith');
        $conversation = $this->conversation($this->storeKb, self::AGENT_A);
        $this->answeredQuestion($conversation, 'Question', '2026-05-10 14:00:00');

        $usage = $this->reader->agentUsage($this->query());
        assertCount(1, $usage);
        assertSame(self::AGENT_A, $usage[0]->agentAdminId);
        assertSame('Anna Smith', $usage[0]->agentName);
        assertSame('a.smith', $usage[0]->agentUsername);
    }

    /** No FK exists and the mirror may not have synced — the agent's activity must still be reported. */
    public function testAgentWithNoMirrorRowStillAppears(): void
    {
        $conversation = $this->conversation($this->storeKb, self::AGENT_B);
        $this->answeredQuestion($conversation, 'Question', '2026-05-10 14:00:00');

        $usage = $this->reader->agentUsage($this->query());
        assertCount(1, $usage);
        assertSame(self::AGENT_B, $usage[0]->agentAdminId);
        assertNull($usage[0]->agentName);
        assertSame('Agent #' . self::AGENT_B, $usage[0]->agentLabel());
    }

    public function testAdminConversationsAreExcludedEntirely(): void
    {
        $agentConversation = $this->conversation($this->storeKb, self::AGENT_A);
        $this->answeredQuestion($agentConversation, 'Agent question', '2026-05-10 14:00:00');

        $adminConversation = $this->conversation($this->otherKb, 5001, participantType: 'admin');
        $this->answeredQuestion($adminConversation, 'Admin question', '2026-05-10 14:05:00');

        $summary = $this->reader->summary($this->query());
        assertSame(1, $summary->questions);
        assertSame(1, $summary->activeAgents);
    }

    // ---------------------------------------------------------------- sessions

    public function testMessagesWithinTheGapAreOneSession(): void
    {
        $conversation = $this->conversation($this->storeKb, self::AGENT_A);
        $this->answeredQuestion($conversation, 'First', '2026-05-10 14:00:00');
        $this->answeredQuestion($conversation, 'Second', '2026-05-10 14:20:00');

        $summary = $this->reader->summary($this->query());
        assertSame(1, $summary->sessions);
        // 14:00:00 first question to 14:20:05 last answer.
        assertSame(1205, $summary->chatSeconds);
    }

    public function testAGapBeyondTheThresholdStartsANewSession(): void
    {
        $conversation = $this->conversation($this->storeKb, self::AGENT_A);
        $this->answeredQuestion($conversation, 'First', '2026-05-10 14:00:00');
        $this->answeredQuestion($conversation, 'Later', '2026-05-10 14:31:00');

        assertSame(2, $this->reader->summary($this->query())->sessions);
    }

    /** The whole reason not to use conversation created→last_message: it would report days. */
    public function testActivityAcrossDaysDoesNotBecomeOneGiantSession(): void
    {
        $conversation = $this->conversation($this->storeKb, self::AGENT_A);
        $this->answeredQuestion($conversation, 'Day one', '2026-05-10 14:00:00');
        $this->answeredQuestion($conversation, 'Day eight', '2026-05-18 14:00:00');

        $summary = $this->reader->summary($this->query());
        assertSame(2, $summary->sessions);
        // Two five-second exchanges, not eight days.
        assertSame(10, $summary->chatSeconds);
    }

    public function testDifferentAgentsAreNeverMergedIntoOneSession(): void
    {
        $a = $this->conversation($this->storeKb, self::AGENT_A);
        $b = $this->conversation($this->storeKb, self::AGENT_B);
        $this->answeredQuestion($a, 'A', '2026-05-10 14:00:00');
        $this->answeredQuestion($b, 'B', '2026-05-10 14:01:00');

        assertSame(2, $this->reader->summary($this->query())->sessions);
    }

    /** The chosen rule: one agent, two stores, inside the gap — a single session, so time is not doubled. */
    public function testOneAgentAcrossTwoStoresInsideTheGapIsOneSession(): void
    {
        $first = $this->conversation($this->storeKb, self::AGENT_A);
        $second = $this->conversation($this->otherKb, self::AGENT_A);
        $this->answeredQuestion($first, 'Store A', '2026-05-10 14:00:00');
        $this->answeredQuestion($second, 'Store B', '2026-05-10 14:10:00');

        $summary = $this->reader->summary($this->query());
        assertSame(1, $summary->sessions);
        assertSame(605, $summary->chatSeconds);
    }

    // ---------------------------------------------------------------- store usage and search

    public function testStoreUsageAggregatesPerKnowledgeBase(): void
    {
        $a = $this->conversation($this->storeKb, self::AGENT_A);
        $b = $this->conversation($this->storeKb, self::AGENT_B);
        $answer = $this->answeredQuestion($a, 'Q1', '2026-05-10 14:00:00', grounded: false);
        $this->answeredQuestion($b, 'Q2', '2026-05-10 14:05:00', grounded: true);
        $this->score($answer, self::AGENT_A, 2);

        $rows = $this->reader->storeUsage($this->query());
        assertCount(1, $rows);
        assertSame('Report Store A', $rows[0]->storeName);
        assertSame(2, $rows[0]->questions);
        assertSame(2, $rows[0]->uniqueAgents);
        assertSame(1, $rows[0]->lowRatings);
        assertSame(1, $rows[0]->fallbackAnswers);
    }

    public function testSearchFiltersDetailRowsOnlyAndNotTheSummary(): void
    {
        $conversation = $this->conversation($this->storeKb, self::AGENT_A);
        $this->answeredQuestion($conversation, 'Delivery hours', '2026-05-10 14:00:00');
        $this->answeredQuestion($conversation, 'Menu prices', '2026-05-10 14:05:00');

        $query = $this->query(search: 'Delivery');
        assertCount(1, $this->reader->list($query)->items);
        // The headline figures deliberately ignore the search.
        assertSame(2, $this->reader->summary($query)->questions);
    }

    // ---------------------------------------------------------------- fixture helpers

    private function query(
        string $from = '2026-05-01',
        string $to = '2026-05-31',
        ChatTypeFilter $type = ChatTypeFilter::All,
        RatingFilter $rating = RatingFilter::All,
        FeedbackFilter $feedback = FeedbackFilter::All,
        AnswerStatusFilter $status = AnswerStatusFilter::All,
        string $search = '',
    ): ChatReportQuery {
        return new ChatReportQuery(
            range: ReportDateRange::fromRequest($from, $to, $this->timeZone, $this->now),
            chatType: $type,
            rating: $rating,
            feedback: $feedback,
            status: $status,
            search: $search,
            perPage: 100,
        );
    }

    private function insertKnowledgeBase(string $slug, string $name, string $purpose): int
    {
        $ts = DbDateTime::format($this->now);
        $this->connection->createCommand()->insert('{{%knowledge_bases}}', [
            'name' => $name,
            'slug' => $slug,
            'openai_vector_store_id' => 'vs_' . $slug,
            'vector_store_status' => 'ready',
            'status' => 'active',
            'purpose' => $purpose,
            'created_at' => $ts,
            'updated_at' => $ts,
        ])->execute();

        return (int) $this->connection->getLastInsertId();
    }

    private function conversation(int $knowledgeBaseId, int $participantId, string $participantType = 'agent'): int
    {
        $ts = DbDateTime::format($this->now);
        $this->connection->createCommand()->insert('{{%conversations}}', [
            'knowledge_base_id' => $knowledgeBaseId,
            'title' => 'Report fixture',
            'participant_type' => $participantType,
            'participant_id' => $participantId,
            'agent_admin_id' => $participantType === 'agent' ? $participantId : null,
            'created_at' => $ts,
            'updated_at' => $ts,
        ])->execute();

        return (int) $this->connection->getLastInsertId();
    }

    private function question(int $conversationId, string $content, string $utc): int
    {
        $this->connection->createCommand()->insert('{{%messages}}', [
            'conversation_id' => $conversationId,
            'role' => 'user',
            'content' => $content,
            'is_grounded' => 0,
            'created_at' => $utc,
        ])->execute();

        return (int) $this->connection->getLastInsertId();
    }

    private function answer(
        int $conversationId,
        int $questionId,
        string $content,
        string $utc,
        bool $superseded = false,
        bool $grounded = true,
        ?string $answerSource = null,
    ): int {
        $this->connection->createCommand()->insert('{{%messages}}', [
            'conversation_id' => $conversationId,
            'role' => 'assistant',
            'content' => $content,
            'is_grounded' => $grounded ? 1 : 0,
            'reply_to_message_id' => $questionId,
            'superseded_at' => $superseded ? $utc : null,
            'answer_source' => $answerSource,
            'created_at' => $utc,
        ])->execute();

        return (int) $this->connection->getLastInsertId();
    }

    /** A question with a current answer five seconds later; returns the answer id. */
    private function answeredQuestion(int $conversationId, string $content, string $utc, bool $grounded = true): int
    {
        $questionId = $this->question($conversationId, $content, $utc);
        $answerAt = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->modify('+5 seconds');

        return $this->answer(
            $conversationId,
            $questionId,
            'Answer to ' . $content,
            DbDateTime::format($answerAt),
            grounded: $grounded,
        );
    }

    private function score(int $answerId, int $agentAdminId, int $score, ?string $comment = null): void
    {
        $this->scoreAs($answerId, 'agent', $agentAdminId, $score, $comment);
    }

    private function scoreAs(int $answerId, string $type, int $participantId, int $score, ?string $comment = null): void
    {
        $ts = DbDateTime::format($this->now);
        $this->connection->createCommand()->insert('{{%chat_answer_scores}}', [
            'message_id' => $answerId,
            'participant_type' => $type,
            'participant_id' => $participantId,
            'score' => $score,
            'feedback_comment' => $comment,
            'dismissed_at' => null,
            'created_at' => $ts,
            'updated_at' => $ts,
        ])->execute();
    }

    private function dismiss(int $answerId, int $agentAdminId): void
    {
        $ts = DbDateTime::format($this->now);
        $this->connection->createCommand()->insert('{{%chat_answer_scores}}', [
            'message_id' => $answerId,
            'participant_type' => 'agent',
            'participant_id' => $agentAdminId,
            'score' => null,
            'feedback_comment' => null,
            'dismissed_at' => $ts,
            'created_at' => $ts,
            'updated_at' => $ts,
        ])->execute();
    }

    private function insertAgentMirror(int $adminId, string $username, string $first, string $last): void
    {
        $ts = DbDateTime::format($this->now);
        $this->connection->createCommand()->insert('{{%order58_agents}}', [
            'admin_id' => $adminId,
            'username' => $username,
            'first_name' => $first,
            'last_name' => $last,
            'status' => 'active',
            'user_type' => 'agent',
            'sync_hash' => str_repeat('a', 64),
            'synced_at' => $ts,
            'created_at' => $ts,
            'updated_at' => $ts,
        ])->execute();
    }

    /**
     * Conversations, messages and scores all cascade from the knowledge base; the agent mirror rows are
     * deleted by their test-only admin ids.
     */
    private function cleanup(): void
    {
        foreach ([self::STORE_SLUG, self::OTHER_SLUG, self::RULES_SLUG] as $slug) {
            IntegrationDb::cleanup($this->connection, '{{%knowledge_bases}}', ['slug' => $slug]);
        }
        IntegrationDb::cleanup(
            $this->connection,
            '{{%order58_agents}}',
            ['admin_id' => [self::AGENT_A, self::AGENT_B]],
        );
    }
}
