<?php

declare(strict_types=1);

namespace App\Order58\Infrastructure;

use App\Integration\Order58Recording\RecordingChannel;
use App\Order58\Domain\CallImportHistoryPage;
use App\Order58\Domain\CallImportHistoryRow;
use App\Order58\Domain\CallImportItem;
use App\Order58\Domain\CallImportRepositoryInterface;
use App\Order58\Domain\Order58ImportStatus;
use App\Shared\Infrastructure\Db\DbDateTime;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Exception\IntegrityException;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

use function is_array;
use function count;
use function is_string;
use function max;

use const SORT_ASC;
use const SORT_DESC;

/**
 * MySQL-backed record of Order58 recording imports.
 *
 * Two details carry the correctness of the whole feature:
 *
 * 1. **The duplicate guard is the unique key, not a lookup.** {@see queueCall()} inserts and catches the
 *    integrity violation. A check-then-insert would be a race two administrators pressing Sync at the
 *    same second could lose, and the cost of losing it is a second paid transcription of the same call.
 * 2. **The claim is a conditional UPDATE.** {@see claimNext()} takes the row only if it is still
 *    `PENDING`, and the affected-row count is the lock token — the same shape
 *    `DbTranscriptionJobRepository::claimNextQueued()` uses, and safe even though a lock file already
 *    guarantees a single worker.
 */
final readonly class DbCallImportRepository implements CallImportRepositoryInterface
{
    private const BATCHES = '{{%order58_call_import_batches}}';
    private const IMPORTS = '{{%order58_call_imports}}';
    private const STORES = '{{%order58_stores}}';

    /** How many rows a claim looks at before giving up, so a burst of contention still terminates. */
    private const CLAIM_CANDIDATES = 10;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function createBatch(
        int $storeSourceId,
        string $triggeredBy,
        ?int $requestedByAdminId,
        string $provider,
        bool $generateAiAudio,
        string $recordingCompany,
        DateTimeImmutable $now,
    ): int {
        $this->connection->createCommand()->insert(self::BATCHES, [
            'store_source_id' => $storeSourceId,
            'triggered_by' => $triggeredBy,
            'requested_by_admin_id' => $requestedByAdminId,
            'transcription_provider' => $provider,
            'generate_ai_audio' => $generateAiAudio ? 1 : 0,
            'recording_company' => $recordingCompany,
            'call_count' => 0,
            'created_at' => DbDateTime::format($now),
        ])->execute();

        return (int) $this->connection->getLastInsertId();
    }

    public function queueCall(
        int $batchId,
        int $storeSourceId,
        string $callSessionId,
        string $callTimeRaw,
        string $callDate,
        ?string $orderId,
        array $channels,
        DateTimeImmutable $now,
    ): int {
        $ts = DbDateTime::format($now);
        $created = 0;

        foreach ($channels as $channel) {
            try {
                $this->connection->createCommand()->insert(self::IMPORTS, [
                    'batch_id' => $batchId,
                    'store_source_id' => $storeSourceId,
                    'call_session_id' => $callSessionId,
                    'channel' => $channel->value,
                    'call_time_raw' => $callTimeRaw,
                    'call_date' => $callDate,
                    'order_id' => $orderId,
                    'status' => Order58ImportStatus::Pending->value,
                    'created_at' => $ts,
                    'updated_at' => $ts,
                ])->execute();

                $created++;
            } catch (IntegrityException) {
                // Already requested for this store. Pressing Sync twice, or the scheduler meeting a call
                // an administrator already took, is ordinary — the key exists so this is a no-op.
                continue;
            }
        }

        if ($created > 0) {
            $this->connection->createCommand()->update(
                self::BATCHES,
                ['call_count' => new Expression('call_count + 1')],
                ['id' => $batchId],
            )->execute();
        }

        return $created;
    }

    public function statusesFor(int $storeSourceId, array $callSessionIds): array
    {
        if ($callSessionIds === []) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = (new Query($this->connection))
            ->select(['call_session_id', 'status'])
            ->from(self::IMPORTS)
            ->where(['store_source_id' => $storeSourceId, 'call_session_id' => $callSessionIds])
            ->all();

        $byCall = [];

        foreach ($rows as $row) {
            $status = Order58ImportStatus::fromStorage((string) $row['status']);

            if ($status !== null) {
                $byCall[(string) $row['call_session_id']][] = $status;
            }
        }

        return $byCall;
    }

    public function claimNext(DateTimeImmutable $now): ?CallImportItem
    {
        $ts = DbDateTime::format($now);

        // Strings, not ints: the driver returns column values as strings, which is why the cast below
        // is not the redundancy it looks like. An annotation claiming `list<int>` here was wrong, and
        // removing the cast on the strength of it turned every claim into a TypeError.
        /** @var list<string> $ids */
        $ids = (new Query($this->connection))
            ->select('id')
            ->from(self::IMPORTS)
            ->where(['status' => Order58ImportStatus::Pending->value])
            ->andWhere(['or', ['next_attempt_at' => null], ['<=', 'next_attempt_at', $ts]])
            ->orderBy(['id' => SORT_ASC])
            ->limit(self::CLAIM_CANDIDATES)
            ->column();

        foreach ($ids as $id) {
            $affected = $this->connection->createCommand()->update(
                self::IMPORTS,
                [
                    'status' => Order58ImportStatus::Fetching->value,
                    'claimed_at' => $ts,
                    'updated_at' => $ts,
                ],
                ['id' => $id, 'status' => Order58ImportStatus::Pending->value],
            )->execute();

            if ($affected === 1) {
                return $this->findItem((int) $id);
            }
        }

        return null;
    }

    public function markImported(
        int $id,
        string $conversationPublicId,
        int $bytes,
        ?float $durationSeconds,
        DateTimeImmutable $now,
    ): void {
        $ts = DbDateTime::format($now);

        $this->connection->createCommand()->update(self::IMPORTS, [
            'status' => Order58ImportStatus::Imported->value,
            'conversation_public_id' => $conversationPublicId,
            'bytes' => $bytes,
            'duration_seconds' => $durationSeconds,
            'error_code' => null,
            'error_message' => null,
            'next_attempt_at' => null,
            'completed_at' => $ts,
            'updated_at' => $ts,
        ], ['id' => $id])->execute();
    }

    public function markSettled(
        int $id,
        Order58ImportStatus $status,
        ?string $errorCode,
        ?string $errorMessage,
        ?int $bytes,
        ?float $durationSeconds,
        DateTimeImmutable $now,
    ): void {
        $ts = DbDateTime::format($now);

        $this->connection->createCommand()->update(self::IMPORTS, [
            'status' => $status->value,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'bytes' => $bytes,
            'duration_seconds' => $durationSeconds,
            'next_attempt_at' => null,
            'completed_at' => $ts,
            'updated_at' => $ts,
        ], ['id' => $id])->execute();
    }

    public function requeue(
        int $id,
        DateTimeImmutable $nextAttemptAt,
        ?string $errorCode,
        ?string $errorMessage,
        DateTimeImmutable $now,
    ): void {
        $ts = DbDateTime::format($now);

        $this->connection->createCommand()->update(self::IMPORTS, [
            'status' => Order58ImportStatus::Pending->value,
            'attempts' => new Expression('attempts + 1'),
            'next_attempt_at' => DbDateTime::format($nextAttemptAt),
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'claimed_at' => null,
            'updated_at' => $ts,
        ], ['id' => $id])->execute();
    }

    public function recoverStuck(DateTimeImmutable $threshold, DateTimeImmutable $now): int
    {
        $ts = DbDateTime::format($now);

        return $this->connection->createCommand()->update(
            self::IMPORTS,
            [
                'status' => Order58ImportStatus::Pending->value,
                'claimed_at' => null,
                'updated_at' => $ts,
            ],
            [
                'and',
                ['status' => Order58ImportStatus::Fetching->value],
                ['<', 'claimed_at', DbDateTime::format($threshold)],
            ],
        )->execute();
    }

    public function retry(int $id, DateTimeImmutable $now): bool
    {
        $ts = DbDateTime::format($now);

        // Guarded in the WHERE rather than by reading first: only a failed row may be retried, and a
        // condition in the statement cannot be overtaken between the read and the write.
        $affected = $this->connection->createCommand()->update(
            self::IMPORTS,
            [
                'status' => Order58ImportStatus::Pending->value,
                'attempts' => 0,
                'next_attempt_at' => null,
                'error_code' => null,
                'error_message' => null,
                'claimed_at' => null,
                'completed_at' => null,
                'updated_at' => $ts,
            ],
            ['id' => $id, 'status' => Order58ImportStatus::Failed->value],
        )->execute();

        return $affected === 1;
    }

    public function history(?int $storeSourceId, int $limit): array
    {
        $query = $this->itemQuery()
            ->addSelect(['store_name' => 's.name'])
            ->leftJoin(['s' => self::STORES], 's.source_id = i.store_source_id')
            // Newest call first. Ordered by the item id rather than by a timestamp because two calls
            // queued in the same second must still have a stable order.
            ->orderBy(['i.id' => SORT_DESC])
            ->limit($limit * 3);

        if ($storeSourceId !== null) {
            $query->andWhere(['i.store_source_id' => $storeSourceId]);
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $query->all();

        return $this->groupIntoCalls($rows, $limit);
    }

    /**
     * One page of history across every store, ordered newest call first.
     *
     * ## Why this is not `history()` with an offset
     *
     * A call is up to three channel rows, and `history()` over-fetches rows and stops once it has enough
     * calls. That is sound for a fixed peek and unusable for paging: an `OFFSET` counted in rows lands
     * mid-call, so the same call appears at the bottom of one page and the top of the next, each time
     * with only some of its cells filled in.
     *
     * So the page is chosen over **calls** and the rows are fetched afterwards:
     *
     *   1. count the distinct calls — the total the pager needs;
     *   2. select this page's call identities, ordered by their newest row;
     *   3. fetch every channel row belonging to those identities.
     *
     * Three queries, whatever the page size, and the third is the same {@see itemQuery()} the
     * store-specific history uses. Nothing here loops over stores or issues a query per row.
     */
    public function historyPage(int $page, int $perPage): CallImportHistoryPage
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        $total = (int) (new Query($this->connection))
            ->from(self::IMPORTS)
            // COUNT over the pair, not over rows: rows would report roughly three times too many and
            // hand the pager pages that do not exist.
            //
            // An Expression, because the builder reads a plain string here as a column name and quotes
            // it into `COUNT(DISTINCT AS ...)`, which is a syntax error rather than a wrong answer.
            ->select(new Expression('COUNT(DISTINCT store_source_id, call_session_id)'))
            ->scalar();

        if ($total === 0) {
            return new CallImportHistoryPage([], 0, $page, $perPage);
        }

        /** @var list<array<string, mixed>> $keys */
        $keys = (new Query($this->connection))
            ->from(self::IMPORTS)
            ->select(['store_source_id', 'call_session_id'])
            ->groupBy(['store_source_id', 'call_session_id'])
            // By the call's newest row, matching how `history()` orders — the item id rather than a
            // timestamp, so calls queued in the same second keep a stable order. An Expression for the
            // same reason as the count above: a key here would be quoted as a column name.
            ->orderBy(new Expression('MAX(id) DESC'))
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->all();

        if ($keys === []) {
            // A page past the end. The action clamps, so this is the race where rows went away between
            // the count and the select; an empty page is a truer answer than a refusal.
            return new CallImportHistoryPage([], $total, $page, $perPage);
        }

        $identities = [];

        foreach ($keys as $key) {
            $identities[] = [
                'i.store_source_id' => (int) $key['store_source_id'],
                'i.call_session_id' => (string) $key['call_session_id'],
            ];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->itemQuery()
            ->addSelect(['store_name' => 's.name'])
            ->leftJoin(['s' => self::STORES], 's.source_id = i.store_source_id')
            // An OR of equality pairs rather than two separate IN lists: separate lists would also match
            // a session id belonging to a different store on this page.
            ->andWhere(['or', ...$identities])
            ->orderBy(['i.id' => SORT_DESC])
            ->all();

        return new CallImportHistoryPage(
            $this->groupIntoCalls($rows, $perPage),
            $total,
            $page,
            $perPage,
        );
    }

    /**
     * Channel rows, newest first, folded into one entry per call.
     *
     * Shared by both history readers so a column added to the table appears on both pages, and so the
     * rule that a call's `updated` is the latest of its channels lives in one place.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<CallImportHistoryRow>
     */
    private function groupIntoCalls(array $rows, int $limit): array
    {
        $calls = [];

        foreach ($rows as $row) {
            $item = $this->item($row);
            $key = (string) $row['store_source_id'] . ':' . (string) $row['call_session_id'];

            if (!isset($calls[$key])) {
                $storeName = $row['store_name'] ?? null;

                $calls[$key] = [
                    'store' => (int) $row['store_source_id'],
                    'name' => is_string($storeName) ? $storeName : '',
                    'session' => (string) $row['call_session_id'],
                    'order' => $this->nullableString($row['order_id'] ?? null),
                    'time' => (string) $row['call_time_raw'],
                    'provider' => $item->provider,
                    'ai' => $item->generateAiAudio,
                    'updated' => $item->updatedAt,
                    'channels' => [],
                ];
            }

            $calls[$key]['channels'][$item->channel->value] = $item;

            if ($item->updatedAt > $calls[$key]['updated']) {
                $calls[$key]['updated'] = $item->updatedAt;
            }
        }

        $history = [];

        foreach ($calls as $call) {
            /** @var array<string, CallImportItem> $channels */
            $channels = $call['channels'];

            $history[] = new CallImportHistoryRow(
                $call['store'],
                $call['name'],
                $call['session'],
                $call['order'],
                $call['time'],
                $channels,
                $call['provider'],
                $call['ai'],
                $call['updated'],
            );

            if (count($history) >= $limit) {
                break;
            }
        }

        return $history;
    }

    public function findItem(int $id): ?CallImportItem
    {
        /** @var array<string, mixed>|null $row */
        $row = $this->itemQuery()->andWhere(['i.id' => $id])->one();

        return is_array($row) ? $this->item($row) : null;
    }

    /**
     * Every column both readers need, with the batch's options folded in.
     *
     * Joined rather than looked up per item: the options are fixed for the batch's lifetime, and a
     * second query per row would turn a page of history into an N+1.
     */
    private function itemQuery(): Query
    {
        return (new Query($this->connection))
            ->select([
                'id' => 'i.id',
                'batch_id' => 'i.batch_id',
                'store_source_id' => 'i.store_source_id',
                'call_session_id' => 'i.call_session_id',
                'channel' => 'i.channel',
                'call_time_raw' => 'i.call_time_raw',
                'call_date' => 'i.call_date',
                'order_id' => 'i.order_id',
                'status' => 'i.status',
                'attempts' => 'i.attempts',
                'error_code' => 'i.error_code',
                'error_message' => 'i.error_message',
                'bytes' => 'i.bytes',
                'duration_seconds' => 'i.duration_seconds',
                'conversation_public_id' => 'i.conversation_public_id',
                'completed_at' => 'i.completed_at',
                'updated_at' => 'i.updated_at',
                'recording_company' => 'b.recording_company',
                'provider' => 'b.transcription_provider',
                'generate_ai_audio' => 'b.generate_ai_audio',
                'requested_by_admin_id' => 'b.requested_by_admin_id',
            ])
            ->from(['i' => self::IMPORTS])
            ->innerJoin(['b' => self::BATCHES], 'b.id = i.batch_id');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function item(array $row): CallImportItem
    {
        return new CallImportItem(
            (int) $row['id'],
            (int) $row['batch_id'],
            (int) $row['store_source_id'],
            (string) $row['call_session_id'],
            RecordingChannel::fromStorage((string) $row['channel']) ?? RecordingChannel::Mixed,
            (string) $row['call_time_raw'],
            (string) $row['call_date'],
            $this->nullableString($row['order_id'] ?? null),
            Order58ImportStatus::fromStorage((string) $row['status']) ?? Order58ImportStatus::Pending,
            (int) $row['attempts'],
            $this->nullableString($row['error_code'] ?? null),
            $this->nullableString($row['error_message'] ?? null),
            $row['bytes'] === null ? null : (int) $row['bytes'],
            $row['duration_seconds'] === null ? null : (float) $row['duration_seconds'],
            $this->nullableString($row['conversation_public_id'] ?? null),
            DbDateTime::parseNullable($this->nullableString($row['completed_at'] ?? null)),
            DbDateTime::parse((string) $row['updated_at']),
            (string) $row['recording_company'],
            (string) $row['provider'],
            (int) $row['generate_ai_audio'] === 1,
            (int) $row['requested_by_admin_id'],
        );
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
