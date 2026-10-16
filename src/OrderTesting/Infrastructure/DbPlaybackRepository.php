<?php

declare(strict_types=1);

namespace App\OrderTesting\Infrastructure;

use App\OrderTesting\Domain\Playback\GapSelection;
use App\OrderTesting\Domain\Playback\GapSelectionRow;
use App\OrderTesting\Domain\Playback\NewPlaybackSession;
use App\OrderTesting\Domain\Playback\PlaybackAgentSummary;
use App\OrderTesting\Domain\Playback\PlaybackEventType;
use App\OrderTesting\Domain\Playback\PlaybackRepositoryInterface;
use App\OrderTesting\Domain\Playback\PlaybackSession;
use App\OrderTesting\Domain\Playback\PlaybackStatus;
use App\Shared\Domain\Clock\ClockInterface;
use RuntimeException;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

use function is_array;
use function max;
use function min;

/**
 * Playback sessions and their events, in MySQL.
 *
 * Reads the two tables this feature owns and nothing else. The recording it names lives in another
 * module's table and is referenced by value — see the migration for why there is no foreign key.
 */
final readonly class DbPlaybackRepository implements PlaybackRepositoryInterface
{
    private const SESSIONS = '{{%agent_playback_sessions}}';
    private const EVENTS = '{{%agent_playback_events}}';

    public function __construct(
        private ConnectionInterface $connection,
        private ClockInterface $clock,
    ) {}

    /**
     * Insert the attempt, or hand back the one this token already opened.
     *
     * The insert is tried first and a duplicate-key failure is the expected second outcome rather than
     * an error: checking first and inserting after leaves a window two simultaneous clicks fit
     * through, and the UNIQUE key is the only thing that closes it. Nothing about the existing row is
     * overwritten — a retry must not be able to change the gap an attempt was started with.
     */
    public function openSession(NewPlaybackSession $session): PlaybackSession
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        try {
            $this->connection->createCommand()->insert(self::SESSIONS, [
                'public_id' => $session->publicId,
                'agent_admin_id' => $session->agentAdminId,
                'agent_display_name' => $session->agentDisplayName,
                'store_source_id' => $session->storeSourceId,
                'source_order_id' => $session->sourceOrderId,
                'job_public_id' => $session->jobPublicId,
                'recording_label' => $session->recordingLabel,
                'gap_ms' => $session->gapMs,
                'message_total' => $session->messageTotal,
                'message_played' => 0,
                'status' => PlaybackStatus::Requested->value,
                'requested_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ])->execute();
        } catch (Throwable) {
            // Already open: this token has been seen. The row it names is the authoritative one.
            $existing = $this->findSession($session->publicId);

            if ($existing !== null) {
                return $existing;
            }

            throw new RuntimeException('The playback attempt could not be opened.');
        }

        $opened = $this->findSession($session->publicId);

        if ($opened === null) {
            throw new RuntimeException('The playback attempt vanished as it was opened.');
        }

        $this->writeEvent($opened, PlaybackEventType::PlayRequested, $opened->gapMs, null, null);

        return $opened;
    }

    public function findSession(string $publicId): ?PlaybackSession
    {
        $row = (new Query($this->connection))
            ->from(self::SESSIONS)
            ->where(['public_id' => $publicId])
            ->one();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * One transition, one event, one transaction.
     *
     * The status rules decide whether anything happens at all. A transition they refuse writes nothing
     * — not the status, not the event — because a late `COMPLETED` from a tab somebody left open is
     * not a thing that happened to a session that was stopped an hour ago.
     */
    public function advance(
        PlaybackSession $session,
        PlaybackEventType $event,
        ?PlaybackStatus $status,
        ?int $messagePlayed,
        ?string $detail,
    ): PlaybackSession {
        if ($status !== null && !$status->mayFollow($session->status)) {
            return $session;
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $changes = ['updated_at' => $now];

        if ($status !== null) {
            $changes['status'] = $status->value;
        }

        // Set once and never cleared: this is what a play is counted by, so a later stop or failure
        // must not be able to erase the fact that it began.
        if ($event === PlaybackEventType::PlayStarted && $session->startedAt === null) {
            $changes['started_at'] = $now;
        }

        if ($status !== null && $status->isFinal()) {
            $changes['finished_at'] = $now;
        }

        if ($messagePlayed !== null) {
            // Never backwards, and never past the total it was opened with.
            $changes['message_played'] = max(
                $session->messagePlayed,
                min($messagePlayed, $session->messageTotal),
            );
        }

        $transaction = $this->connection->beginTransaction();

        try {
            $this->connection->createCommand()
                ->update(self::SESSIONS, $changes, ['id' => $session->id])
                ->execute();

            $this->writeEvent($session, $event, $session->gapMs, $messagePlayed, $detail);
            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }

        return $this->findSession($session->publicId) ?? $session;
    }

    /**
     * A dropdown change, which is an event and not an attempt.
     *
     * `session_id` stays null, which the table's own CHECK requires for this type — so a selection can
     * never be written in a shape that a session query would pick up, or counted as a play by any
     * reader present or future.
     */
    public function recordSelection(GapSelection $selection): void
    {
        $this->connection->createCommand()->insert(self::EVENTS, [
            'session_id' => null,
            'event_type' => PlaybackEventType::GapSelected->value,
            'agent_admin_id' => $selection->agentAdminId,
            'agent_display_name' => $selection->agentDisplayName,
            'store_source_id' => $selection->storeSourceId,
            'source_order_id' => $selection->sourceOrderId,
            'job_public_id' => $selection->jobPublicId,
            'recording_label' => $selection->recordingLabel,
            'gap_ms' => $selection->gapMs,
            'message_index' => null,
            'detail' => null,
            'created_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ])->execute();
    }

    /**
     * One row per agent per recording.
     *
     * Per recording, not per order: an order holds up to three of them and a figure that added them
     * together would be true of none. The six counts are separate columns of one grouped scan over the
     * recording index, so the modal costs one query however many agents appear in it.
     *
     * `play_starts` counts `started_at`, not a status — a session that failed before the engine spoke
     * ended as ERROR having played nothing, and must not count as a play.
     *
     * @param list<string> $jobPublicIds
     * @return list<PlaybackAgentSummary>
     */
    public function summaryFor(int $storeSourceId, ?int $sourceOrderId, array $jobPublicIds): array
    {
        if ($jobPublicIds === []) {
            return [];
        }

        $rows = (new Query($this->connection))
            ->select([
                'agent_admin_id',
                'agent_display_name' => new Expression('MAX(`agent_display_name`)'),
                'job_public_id',
                'recording_label' => new Expression('MAX(`recording_label`)'),
                'play_requests' => new Expression('COUNT(*)'),
                'play_starts' => new Expression('SUM(`started_at` IS NOT NULL)'),
                'completed' => new Expression("SUM(`status` = 'COMPLETED')"),
                'stopped' => new Expression("SUM(`status` = 'STOPPED')"),
                'errors_before' => new Expression("SUM(`status` = 'ERROR' AND `started_at` IS NULL)"),
                'errors_after' => new Expression("SUM(`status` = 'ERROR' AND `started_at` IS NOT NULL)"),
                'last_started_at' => new Expression('MAX(`started_at`)'),
            ])
            ->from(self::SESSIONS)
            ->where($this->scope($storeSourceId, $sourceOrderId, $jobPublicIds))
            ->groupBy(['agent_admin_id', 'job_public_id'])
            ->orderBy(['last_started_at' => SORT_DESC, 'agent_admin_id' => SORT_ASC])
            ->all();

        $selections = $this->selectionCounts($storeSourceId, $sourceOrderId, $jobPublicIds);
        $out = [];

        foreach ($rows as $row) {
            // A driver row is an array here; the query builder's signature allows an object for the
            // object-hydration mode this application does not use. Skipped rather than cast, because
            // a row that is not an array is not a row this code wrote.
            if (!is_array($row)) {
                continue;
            }

            $agentId = (int) $row['agent_admin_id'];
            $job = (string) $row['job_public_id'];

            $out[] = new PlaybackAgentSummary(
                agentAdminId: $agentId,
                agentDisplayName: (string) $row['agent_display_name'],
                jobPublicId: $job,
                recordingLabel: $row['recording_label'] === null ? null : (string) $row['recording_label'],
                playRequests: (int) $row['play_requests'],
                playStarts: (int) $row['play_starts'],
                completed: (int) $row['completed'],
                stopped: (int) $row['stopped'],
                errorsBeforeStart: (int) $row['errors_before'],
                errorsAfterStart: (int) $row['errors_after'],
                gapSelections: $selections[$agentId . '|' . $job] ?? 0,
                lastStartedAt: $row['last_started_at'] === null ? null : (string) $row['last_started_at'],
            );
        }

        return $out;
    }

    /**
     * Selections counted separately, and joined in PHP rather than in SQL.
     *
     * They live in the other table and have no session, so a join would be an outer join over two
     * different groupings — and an agent who only ever changed the dropdown has no session row for a
     * join to hang on. Two small indexed scans say what one awkward query would.
     *
     * @param list<string> $jobPublicIds
     * @return array<string, int>
     */
    private function selectionCounts(int $storeSourceId, ?int $sourceOrderId, array $jobPublicIds): array
    {
        $rows = (new Query($this->connection))
            ->select([
                'agent_admin_id',
                'job_public_id',
                'selections' => new Expression('COUNT(*)'),
            ])
            ->from(self::EVENTS)
            ->where($this->scope($storeSourceId, $sourceOrderId, $jobPublicIds))
            ->andWhere(['event_type' => PlaybackEventType::GapSelected->value])
            ->groupBy(['agent_admin_id', 'job_public_id'])
            ->all();

        $out = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $key = (string) $row['agent_admin_id'] . '|' . (string) $row['job_public_id'];
            $out[$key] = (int) $row['selections'];
        }

        return $out;
    }

    /**
     * @param list<string> $jobPublicIds
     * @return list<PlaybackSession>
     */
    public function historyFor(
        int $storeSourceId,
        ?int $sourceOrderId,
        array $jobPublicIds,
        int $agentAdminId,
        int $limit,
        int $offset,
    ): array {
        if ($jobPublicIds === []) {
            return [];
        }

        $rows = (new Query($this->connection))
            ->from(self::SESSIONS)
            ->where($this->scope($storeSourceId, $sourceOrderId, $jobPublicIds))
            ->andWhere(['agent_admin_id' => $agentAdminId])
            // Newest first, and `id` behind it so two attempts in the same second keep a stable order
            // across pages — without it a reader paging through could see one twice and never another.
            ->orderBy(['requested_at' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit)
            ->offset($offset)
            ->all();

        $out = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = $this->hydrate($row);
            }
        }

        return $out;
    }

    /** @param list<string> $jobPublicIds */
    public function countHistoryFor(
        int $storeSourceId,
        ?int $sourceOrderId,
        array $jobPublicIds,
        int $agentAdminId,
    ): int {
        if ($jobPublicIds === []) {
            return 0;
        }

        return (int) (new Query($this->connection))
            ->from(self::SESSIONS)
            ->where($this->scope($storeSourceId, $sourceOrderId, $jobPublicIds))
            ->andWhere(['agent_admin_id' => $agentAdminId])
            ->count();
    }

    /**
     * @param list<string> $jobPublicIds
     * @return list<GapSelectionRow>
     */
    public function selectionsFor(
        int $storeSourceId,
        ?int $sourceOrderId,
        array $jobPublicIds,
        int $agentAdminId,
        int $limit,
    ): array {
        if ($jobPublicIds === []) {
            return [];
        }

        $rows = (new Query($this->connection))
            ->select(['gap_ms', 'recording_label', 'created_at'])
            ->from(self::EVENTS)
            ->where($this->scope($storeSourceId, $sourceOrderId, $jobPublicIds))
            ->andWhere(['event_type' => PlaybackEventType::GapSelected->value])
            ->andWhere(['agent_admin_id' => $agentAdminId])
            ->orderBy(['id' => SORT_DESC])
            ->limit($limit)
            ->all();

        $out = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $out[] = new GapSelectionRow(
                (int) $row['gap_ms'],
                $row['recording_label'] === null ? null : (string) $row['recording_label'],
                (string) $row['created_at'],
            );
        }

        return $out;
    }

    /**
     * The scope every read is confined to: this store, this order, these recordings.
     *
     * The order id is compared as a value including its null, because a recording that names no order
     * must match other rows that name none rather than every row in the store.
     *
     * @param list<string> $jobPublicIds
     * @return array<string, mixed>
     */
    private function scope(int $storeSourceId, ?int $sourceOrderId, array $jobPublicIds): array
    {
        return [
            'store_source_id' => $storeSourceId,
            'source_order_id' => $sourceOrderId,
            'job_public_id' => $jobPublicIds,
        ];
    }

    private function writeEvent(
        PlaybackSession $session,
        PlaybackEventType $event,
        ?int $gapMs,
        ?int $messageIndex,
        ?string $detail,
    ): void {
        $this->connection->createCommand()->insert(self::EVENTS, [
            'session_id' => $session->id,
            'event_type' => $event->value,
            'agent_admin_id' => $session->agentAdminId,
            'agent_display_name' => $session->agentDisplayName,
            'store_source_id' => $session->storeSourceId,
            'source_order_id' => $session->sourceOrderId,
            'job_public_id' => $session->jobPublicId,
            'recording_label' => $session->recordingLabel,
            'gap_ms' => $gapMs,
            'message_index' => $messageIndex,
            'detail' => $detail,
            'created_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ])->execute();
    }

    /** @param array<array-key, mixed> $row */
    private function hydrate(array $row): PlaybackSession
    {
        return new PlaybackSession(
            id: (int) $row['id'],
            publicId: (string) $row['public_id'],
            agentAdminId: (int) $row['agent_admin_id'],
            agentDisplayName: (string) $row['agent_display_name'],
            storeSourceId: (int) $row['store_source_id'],
            sourceOrderId: $row['source_order_id'] === null ? null : (int) $row['source_order_id'],
            jobPublicId: (string) $row['job_public_id'],
            recordingLabel: $row['recording_label'] === null ? null : (string) $row['recording_label'],
            gapMs: (int) $row['gap_ms'],
            messageTotal: (int) $row['message_total'],
            messagePlayed: (int) $row['message_played'],
            status: PlaybackStatus::from((string) $row['status']),
            requestedAt: (string) $row['requested_at'],
            startedAt: $row['started_at'] === null ? null : (string) $row['started_at'],
            finishedAt: $row['finished_at'] === null ? null : (string) $row['finished_at'],
        );
    }
}
