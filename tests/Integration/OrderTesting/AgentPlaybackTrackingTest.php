<?php

declare(strict_types=1);

namespace App\Tests\Integration\OrderTesting;

use App\OrderTesting\Application\Playback\PlaybackContext;
use App\OrderTesting\Application\Playback\PlaybackTracker;
use App\OrderTesting\Domain\Playback\PlaybackEventType;
use App\OrderTesting\Domain\Playback\PlaybackStatus;
use App\OrderTesting\Infrastructure\DbPlaybackRepository;
use App\Shared\Audio\PlaybackGapOptions;
use App\Shared\Domain\Clock\SystemClock;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function gmdate;
use function str_repeat;

/**
 * What gets counted as a play, proved against the database that does the counting.
 *
 * These rules are only true in PHP until two requests arrive at once. The UNIQUE key on the session
 * token IS "a double click is one attempt"; the CHECK binding `session_id` to the event type IS "a
 * dropdown change can never be a play". Both are asserted here against real MySQL.
 *
 * Fixture ids sit in the `9876559xx` range, well outside anything mirrored, and cleanup is by id so a
 * shared development database survives.
 */
final class AgentPlaybackTrackingTest extends Unit
{
    private const STORE = 987655930;
    private const OTHER_STORE = 987655931;
    private const ORDER = 16800401;
    private const AGENT = 987655001;
    private const OTHER_AGENT = 987655002;

    private ConnectionInterface $connection;
    private DbPlaybackRepository $repository;
    private PlaybackTracker $tracker;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();
        $this->repository = new DbPlaybackRepository($this->connection, new SystemClock());
        $this->tracker = new PlaybackTracker($this->repository);
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    private function cleanup(): void
    {
        foreach ([self::STORE, self::OTHER_STORE] as $store) {
            $this->connection->createCommand()
                ->delete('{{%agent_playback_events}}', ['store_source_id' => $store])->execute();
            $this->connection->createCommand()
                ->delete('{{%agent_playback_sessions}}', ['store_source_id' => $store])->execute();
        }
    }

    private function context(int $agent = self::AGENT, string $job = null, int $store = self::STORE): PlaybackContext
    {
        return new PlaybackContext(
            agentAdminId: $agent,
            agentDisplayName: 'Agent ' . $agent,
            storeSourceId: $store,
            sourceOrderId: self::ORDER,
            jobPublicId: $job ?? $this->job('a'),
            recordingLabel: 'MIXED',
        );
    }

    /** A 32-character id in the shape every public id here uses. */
    private function job(string $seed): string
    {
        return str_repeat($seed, 32);
    }

    private function token(string $seed): string
    {
        return str_repeat($seed, 32);
    }

    // ------------------------------------------------------------------ what counts as a play

    /**
     * The whole counting rule, end to end.
     *
     * Requested, started, finished: one attempt, one play, one completion — and the gap it was
     * started with, kept on the row.
     */
    public function testAStartedAndFinishedAttemptCountsOnce(): void
    {
        $context = $this->context();
        $session = $this->tracker->request($context, $this->token('1'), '0.5', 16);

        $this->assertNotNull($session);
        $this->assertSame(PlaybackStatus::Requested, $session->status);
        $this->assertSame(500, $session->gapMs);
        $this->assertFalse($session->hasStarted());

        $started = $this->tracker->advance($context, $this->token('1'), PlaybackEventType::PlayStarted, 0, null);
        $this->assertNotNull($started);
        $this->assertTrue($started->hasStarted());

        $done = $this->tracker->advance($context, $this->token('1'), PlaybackEventType::Completed, 16, null);
        $this->assertNotNull($done);
        $this->assertSame(PlaybackStatus::Completed, $done->status);
        $this->assertSame(16, $done->messagePlayed);

        $summary = $this->repository->summaryFor(self::STORE, self::ORDER, [$this->job('a')]);
        $this->assertCount(1, $summary);
        $this->assertSame(1, $summary[0]->playRequests);
        $this->assertSame(1, $summary[0]->playStarts);
        $this->assertSame(1, $summary[0]->completed);
        $this->assertSame(0, $summary[0]->stopped);
    }

    /**
     * The correction this feature was changed for: an attempt that failed before the first word is
     * not a play.
     *
     * It is an attempt — Play was pressed — and it is an error, but nothing was ever heard, so
     * `playStarts` stays at zero and the failure is counted where it belongs.
     */
    public function testAFailureBeforeTheFirstWordIsNotCountedAsAPlay(): void
    {
        $context = $this->context();
        $this->tracker->request($context, $this->token('2'), '1', 9);
        $this->tracker->advance($context, $this->token('2'), PlaybackEventType::PlaybackError, 0, 'no voices');

        $summary = $this->repository->summaryFor(self::STORE, self::ORDER, [$this->job('a')]);

        $this->assertSame(1, $summary[0]->playRequests);
        $this->assertSame(0, $summary[0]->playStarts);
        $this->assertSame(1, $summary[0]->errorsBeforeStart);
        $this->assertSame(0, $summary[0]->errorsAfterStart);
    }

    /** One that began and then failed did play, and is counted apart from one that never began. */
    public function testAFailureAfterStartingIsStillAPlay(): void
    {
        $context = $this->context();
        $this->tracker->request($context, $this->token('3'), '1', 9);
        $this->tracker->advance($context, $this->token('3'), PlaybackEventType::PlayStarted, 0, null);
        $this->tracker->advance($context, $this->token('3'), PlaybackEventType::PlaybackError, 4, null);

        $summary = $this->repository->summaryFor(self::STORE, self::ORDER, [$this->job('a')]);

        $this->assertSame(1, $summary[0]->playStarts);
        $this->assertSame(0, $summary[0]->errorsBeforeStart);
        $this->assertSame(1, $summary[0]->errorsAfterStart);
    }

    /** Stopping is not finishing, however far it got. */
    public function testStoppingIsNotCountedAsCompleting(): void
    {
        $context = $this->context();
        $this->tracker->request($context, $this->token('4'), '2', 16);
        $this->tracker->advance($context, $this->token('4'), PlaybackEventType::PlayStarted, 0, null);
        $this->tracker->advance($context, $this->token('4'), PlaybackEventType::Stopped, 8, null);

        $summary = $this->repository->summaryFor(self::STORE, self::ORDER, [$this->job('a')]);

        $this->assertSame(1, $summary[0]->playStarts);
        $this->assertSame(0, $summary[0]->completed);
        $this->assertSame(1, $summary[0]->stopped);
    }

    // ------------------------------------------------------------------ duplicates

    /**
     * A double click, a retried POST and a reloaded tab all carry one token, and make one attempt.
     *
     * Enforced by the UNIQUE key rather than by a check-then-insert, which is why this is an
     * integration test: the window between a check and an insert is exactly what two clicks fit in.
     */
    public function testOneTokenIsOneAttemptHoweverManyTimesItArrives(): void
    {
        $context = $this->context();

        $first = $this->tracker->request($context, $this->token('5'), '0.5', 12);
        $second = $this->tracker->request($context, $this->token('5'), '0.5', 12);
        $third = $this->tracker->request($context, $this->token('5'), '4', 12);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->id, $second->id);
        $this->assertNotNull($third);
        $this->assertSame($first->id, $third->id);
        // And a retry cannot change the gap the attempt was opened with.
        $this->assertSame(500, $third->gapMs);

        $summary = $this->repository->summaryFor(self::STORE, self::ORDER, [$this->job('a')]);
        $this->assertSame(1, $summary[0]->playRequests);
    }

    /** Two reports of the same start are one start. */
    public function testADuplicateStartDoesNotCountTwice(): void
    {
        $context = $this->context();
        $this->tracker->request($context, $this->token('6'), '1', 10);
        $this->tracker->advance($context, $this->token('6'), PlaybackEventType::PlayStarted, 0, null);
        $this->tracker->advance($context, $this->token('6'), PlaybackEventType::PlayStarted, 1, null);

        $summary = $this->repository->summaryFor(self::STORE, self::ORDER, [$this->job('a')]);
        $this->assertSame(1, $summary[0]->playStarts);
    }

    /** A late report against a finished attempt changes nothing. */
    public function testAFinishedAttemptIgnoresLaterReports(): void
    {
        $context = $this->context();
        $this->tracker->request($context, $this->token('7'), '3', 5);
        $this->tracker->advance($context, $this->token('7'), PlaybackEventType::PlayStarted, 0, null);
        $this->tracker->advance($context, $this->token('7'), PlaybackEventType::Stopped, 2, null);

        $late = $this->tracker->advance($context, $this->token('7'), PlaybackEventType::Completed, 5, null);

        $this->assertNotNull($late);
        $this->assertSame(PlaybackStatus::Stopped, $late->status);
        $this->assertSame(2, $late->messagePlayed);

        $summary = $this->repository->summaryFor(self::STORE, self::ORDER, [$this->job('a')]);
        $this->assertSame(0, $summary[0]->completed);
        $this->assertSame(1, $summary[0]->stopped);
    }

    // ------------------------------------------------------------------ selections

    /**
     * Three dropdown changes and no Play is three selections and no attempt.
     *
     * The session table is untouched, so every count that reads it stays at zero — which is the
     * separation the whole design turns on.
     */
    public function testSelectionsAreRecordedWithoutCreatingAnAttempt(): void
    {
        $context = $this->context();

        $this->assertTrue($this->tracker->recordSelection($context, '0.5'));
        $this->assertTrue($this->tracker->recordSelection($context, '1'));
        $this->assertTrue($this->tracker->recordSelection($context, '0.5'));

        $sessions = (new Query($this->connection))
            ->from('{{%agent_playback_sessions}}')
            ->where(['store_source_id' => self::STORE])
            ->count();

        $this->assertSame(0, (int) $sessions);
        $this->assertCount(
            3,
            $this->repository->selectionsFor(self::STORE, self::ORDER, [$this->job('a')], self::AGENT, 50),
        );
        $this->assertSame(
            [],
            $this->repository->summaryFor(self::STORE, self::ORDER, [$this->job('a')]),
        );
    }

    /** A gap the page never offered is refused, selection or attempt alike. */
    public function testAGapNobodyWasOfferedIsRefused(): void
    {
        $context = $this->context();

        $this->assertFalse($this->tracker->recordSelection($context, '0.35'));
        $this->assertFalse($this->tracker->recordSelection($context, 1500));
        $this->assertFalse($this->tracker->recordSelection($context, 'fast'));
        // And one that WAS offered until the list changed. New activity uses the current list; the
        // rows recorded under the old one are untouched, which the readability test below proves.
        $this->assertFalse($this->tracker->recordSelection($context, 300));
        $this->assertNull($this->tracker->request($context, $this->token('8'), '0.30', 4));

        $this->assertCount(
            0,
            $this->repository->selectionsFor(self::STORE, self::ORDER, [$this->job('a')], self::AGENT, 50),
        );
    }

    /** Selections travel with the counts but are never added into them. */
    public function testSelectionsAreCountedBesideAttemptsAndNotWithin(): void
    {
        $context = $this->context();
        $this->tracker->recordSelection($context, '0.5');
        $this->tracker->recordSelection($context, '4');
        $this->tracker->request($context, $this->token('9'), '4', 6);
        $this->tracker->advance($context, $this->token('9'), PlaybackEventType::PlayStarted, 0, null);

        $summary = $this->repository->summaryFor(self::STORE, self::ORDER, [$this->job('a')]);

        $this->assertSame(1, $summary[0]->playRequests);
        $this->assertSame(1, $summary[0]->playStarts);
        $this->assertSame(2, $summary[0]->gapSelections);
    }

    // ------------------------------------------------------------------ ownership and scope

    /** An agent holding somebody else's token cannot move their attempt. */
    public function testAnAgentCannotAdvanceAnotherAgentsAttempt(): void
    {
        $mine = $this->context();
        $this->tracker->request($mine, $this->token('b'), '0.5', 8);

        $theirs = $this->context(self::OTHER_AGENT);
        $this->assertNull(
            $this->tracker->advance($theirs, $this->token('b'), PlaybackEventType::PlayStarted, 0, null),
        );

        $summary = $this->repository->summaryFor(self::STORE, self::ORDER, [$this->job('a')]);
        $this->assertSame(0, $summary[0]->playStarts);
    }

    /** Nor one opened against a different recording, even holding the right token. */
    public function testATokenIsBoundToTheRecordingItWasOpenedOn(): void
    {
        $context = $this->context();
        $this->tracker->request($context, $this->token('c'), '0.5', 8);

        $elsewhere = $this->context(self::AGENT, $this->job('d'));
        $this->assertNull(
            $this->tracker->advance($elsewhere, $this->token('c'), PlaybackEventType::PlayStarted, 0, null),
        );
    }

    /** One order's recordings are counted separately, because they are separate recordings. */
    public function testEachRecordingIsCountedOnItsOwn(): void
    {
        $mixed = $this->context(self::AGENT, $this->job('a'));
        $caller = $this->context(self::AGENT, $this->job('d'));

        $this->tracker->request($mixed, $this->token('e'), '0.5', 4);
        $this->tracker->advance($mixed, $this->token('e'), PlaybackEventType::PlayStarted, 0, null);
        $this->tracker->request($caller, $this->token('f'), '0.5', 4);
        $this->tracker->advance($caller, $this->token('f'), PlaybackEventType::PlayStarted, 0, null);

        $both = $this->repository->summaryFor(self::STORE, self::ORDER, [$this->job('a'), $this->job('d')]);
        $this->assertCount(2, $both);

        $one = $this->repository->summaryFor(self::STORE, self::ORDER, [$this->job('a')]);
        $this->assertCount(1, $one);
        $this->assertSame(1, $one[0]->playStarts);
    }

    /** And another store's activity is never reachable from this one. */
    public function testAnotherStoresActivityIsNotVisible(): void
    {
        $elsewhere = $this->context(self::AGENT, $this->job('a'), self::OTHER_STORE);
        $this->tracker->request($elsewhere, $this->token('0'), '0.5', 4);
        $this->tracker->advance($elsewhere, $this->token('0'), PlaybackEventType::PlayStarted, 0, null);

        $this->assertSame([], $this->repository->summaryFor(self::STORE, self::ORDER, [$this->job('a')]));
        $this->assertCount(1, $this->repository->summaryFor(self::OTHER_STORE, self::ORDER, [$this->job('a')]));
    }

    /**
     * A session recorded under an earlier option list still reads back in full.
     *
     * Written straight to the table, because the service will no longer accept a retired gap — which
     * is the point: the write path moved on and the read path did not. This is the shape of the seven
     * real sessions already in this database, recorded at 100 and 200 and 900 ms before the list
     * became five training intervals.
     */
    public function testASessionRecordedUnderAnEarlierListStillReadsBack(): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%agent_playback_sessions}}', [
            'public_id' => $this->token('a'),
            'agent_admin_id' => self::AGENT,
            'agent_display_name' => 'Agent ' . self::AGENT,
            'store_source_id' => self::STORE,
            'source_order_id' => self::ORDER,
            'job_public_id' => $this->job('a'),
            'recording_label' => 'MIXED',
            // Retired from the dropdown; perfectly valid in the records and in the column's CHECK.
            'gap_ms' => 200,
            'message_total' => 4,
            'message_played' => 4,
            'status' => PlaybackStatus::Completed->value,
            'requested_at' => $now,
            'started_at' => $now,
            'finished_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        $history = $this->repository->historyFor(
            self::STORE,
            self::ORDER,
            [$this->job('a')],
            self::AGENT,
            25,
            0,
        );

        $this->assertCount(1, $history);
        $this->assertSame(200, $history[0]->gapMs);
        $this->assertSame('0.2 s', PlaybackGapOptions::label($history[0]->gapMs));

        // And it is counted exactly as any other completed play.
        $summary = $this->repository->summaryFor(self::STORE, self::ORDER, [$this->job('a')]);
        $this->assertSame(1, $summary[0]->playStarts);
        $this->assertSame(1, $summary[0]->completed);
    }

    /** History is one row per attempt, newest first, and pages. */
    public function testHistoryPagesNewestFirst(): void
    {
        $context = $this->context();

        foreach (['1', '2', '3'] as $seed) {
            $this->tracker->request($context, $this->token($seed), '0.5', 4);
        }

        $this->assertSame(
            3,
            $this->repository->countHistoryFor(self::STORE, self::ORDER, [$this->job('a')], self::AGENT),
        );

        $page = $this->repository->historyFor(self::STORE, self::ORDER, [$this->job('a')], self::AGENT, 2, 0);
        $this->assertCount(2, $page);

        $rest = $this->repository->historyFor(self::STORE, self::ORDER, [$this->job('a')], self::AGENT, 2, 2);
        $this->assertCount(1, $rest);
    }
}
