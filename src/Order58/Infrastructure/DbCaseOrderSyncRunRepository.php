<?php

declare(strict_types=1);

namespace App\Order58\Infrastructure;

use App\Order58\Domain\CaseOrders\CaseOrderSyncRun;
use App\Order58\Domain\CaseOrders\CaseOrderSyncRunRepositoryInterface;
use App\Order58\Domain\CaseOrders\CaseOrderSyncStatus;
use App\Shared\Infrastructure\Db\DbDateTime;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Exception\IntegrityException;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

use function array_map;
use function is_numeric;
use function is_string;
use function mb_substr;

use const SORT_DESC;

/**
 * The daily allowance, held by a unique index.
 *
 * ## The claim is the whole rate limit
 *
 * `claim()` inserts against `UNIQUE (business_day)`. There is no preceding read, so there is no window
 * between checking and acting — two tabs, two operators and a scheduler all race on the index and the
 * database picks one. The loser catches {@see IntegrityException} and is told the day is taken.
 *
 * That is why the table exists at all. A counter or a "last run at" column would both have to be read
 * before being acted on, and the provider's limit is one a day: a second request is not untidy, it is
 * a breach of the client's agreement that cannot be undone afterwards.
 *
 * ## The payload is selected only when it is asked for
 *
 * `staged_payload` is up to sixteen megabytes and the status endpoint polls this table every few
 * seconds. No projection here includes it — {@see stagedPayload()} is its own query, called once, by
 * the one caller that imports.
 */
final readonly class DbCaseOrderSyncRunRepository implements CaseOrderSyncRunRepositoryInterface
{
    private const TABLE = '{{%order58_case_order_sync_runs}}';

    /** `error_summary` is TEXT, but this is rendered on a page; a page needs a sentence. */
    private const MAX_SUMMARY = 1000;

    public function __construct(private ConnectionInterface $connection) {}

    public function claim(
        string $businessDay,
        string $timezone,
        string $triggerSource,
        DateTimeImmutable $leaseUntil,
        DateTimeImmutable $now,
    ): ?CaseOrderSyncRun {
        $stamp = DbDateTime::format($now);

        try {
            $this->connection->createCommand()->insert(self::TABLE, [
                'business_day' => $businessDay,
                'timezone' => $timezone,
                'trigger_source' => mb_substr($triggerSource, 0, 32),
                'status' => CaseOrderSyncStatus::Claimed->value,
                'started_at' => $stamp,
                'lease_expires_at' => DbDateTime::format($leaseUntil),
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ])->execute();
        } catch (IntegrityException) {
            // `ux_case_order_sync_runs_active` refused it: another run is already in flight. Several
            // runs may share a day — what may not be shared is being unfinished.
            return null;
        }

        return $this->find((int) $this->connection->getLastInsertID());
    }

    public function active(): ?CaseOrderSyncRun
    {
        return $this->one($this->select()->where(['active_guard' => 1]));
    }

    public function lastCompleted(): ?CaseOrderSyncRun
    {
        return $this->one(
            $this->select()
                ->where(['status' => CaseOrderSyncStatus::Completed->value])
                ->orderBy(['completed_at' => SORT_DESC, 'id' => SORT_DESC]),
        );
    }

    /**
     * The most recent run the provider told to wait, whether or not that wait has elapsed.
     *
     * Ordered by the instant the provider named rather than by run id: a later run that was never
     * rate limited says nothing about the restriction, and must not be allowed to hide one that is
     * still in force. The caller decides whether it has passed.
     */
    public function lastProviderRateLimited(): ?CaseOrderSyncRun
    {
        return $this->one(
            $this->select()
                ->where(['not', ['provider_retry_after' => null]])
                ->orderBy(['provider_retry_after' => SORT_DESC, 'id' => SORT_DESC]),
        );
    }

    public function recoverExpired(DateTimeImmutable $now): int
    {
        $stamp = DbDateTime::format($now);

        /*
         * Failed at `abandoned`, never at `request`.
         *
         * The stage decides whether the day's allowance is reported as spent, and a process that died
         * mid-run may well have sent the request already. Claiming otherwise would invite a second
         * call to a provider that permits few.
         */
        return $this->connection->createCommand()->update(
            self::TABLE,
            [
                'status' => CaseOrderSyncStatus::Failed->value,
                'stage' => CaseOrderSyncRun::STAGE_ABANDONED,
                'completed_at' => $stamp,
                'error_summary' => 'The synchronisation stopped without reporting. Its lease expired, '
                    . 'so it was closed. Whether the provider was contacted is not known, and nothing '
                    . 'was retried automatically.',
                'updated_at' => $stamp,
            ],
            ['and', ['active_guard' => 1], ['<', 'lease_expires_at', $stamp]],
        )->execute();
    }

    public function forDay(string $businessDay): ?CaseOrderSyncRun
    {
        return $this->one(
            $this->select()
                ->where(['business_day' => $businessDay])
                ->orderBy(['started_at' => SORT_DESC, 'id' => SORT_DESC]),
        );
    }

    public function page(int $limit, int $offset = 0): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->select()
            ->orderBy(['started_at' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit)
            ->offset($offset)
            ->all();

        return array_map(fn(array $row): CaseOrderSyncRun => $this->hydrate($row), $rows);
    }

    public function count(): int
    {
        return (int) (new Query($this->connection))->from(self::TABLE)->count();
    }

    public function latest(): ?CaseOrderSyncRun
    {
        return $this->one($this->select()->orderBy(['business_day' => SORT_DESC, 'id' => SORT_DESC]));
    }

    public function find(int $id): ?CaseOrderSyncRun
    {
        return $this->one($this->select()->where(['id' => $id]));
    }

    public function markFetched(
        int $id,
        int $httpStatus,
        int $responseBytes,
        string $sanitizedPayload,
        DateTimeImmutable $now,
    ): void {
        $stamp = DbDateTime::format($now);

        $this->connection->createCommand()->update(self::TABLE, [
            // FETCHED, so a reader can tell that the day's request HAS been spent. See the enum.
            'status' => CaseOrderSyncStatus::Fetched->value,
            'fetched_at' => $stamp,
            'http_status' => $httpStatus,
            'response_bytes' => $responseBytes,
            'staged_payload' => $sanitizedPayload,
            'updated_at' => $stamp,
        ], ['id' => $id])->execute();
    }

    public function markProcessing(int $id, int $ordersReceived, DateTimeImmutable $now): void
    {
        $stamp = DbDateTime::format($now);

        $this->connection->createCommand()->update(self::TABLE, [
            'status' => CaseOrderSyncStatus::Processing->value,
            'orders_received' => $ordersReceived,
            'updated_at' => $stamp,
        ], ['id' => $id])->execute();
    }

    public function complete(int $id, array $counts, ?string $summary, DateTimeImmutable $now): void
    {
        $stamp = DbDateTime::format($now);

        $this->connection->createCommand()->update(self::TABLE, [
            'status' => CaseOrderSyncStatus::Completed->value,
            'stage' => null,
            'completed_at' => $stamp,
            'orders_inserted' => $counts['inserted'],
            'orders_updated' => $counts['updated'],
            'orders_skipped' => $counts['skipped'],
            'orders_unchanged' => $counts['unchanged'],
            'items_imported' => $counts['items'],
            'failures' => $counts['failures'],
            // Kept on a COMPLETED run as well: "199 of 200 imported, and here is the one" is a
            // success with something to read, not a failure.
            'error_summary' => $summary === null ? null : mb_substr($summary, 0, self::MAX_SUMMARY),
            'updated_at' => $stamp,
        ], ['id' => $id])->execute();
    }

    public function fail(
        int $id,
        string $stage,
        string $summary,
        DateTimeImmutable $now,
        ?DateTimeImmutable $providerRetryAfter = null,
    ): void {
        $stamp = DbDateTime::format($now);

        $values = [
            'status' => CaseOrderSyncStatus::Failed->value,
            'stage' => mb_substr($stage, 0, 32),
            'completed_at' => $stamp,
            'error_summary' => mb_substr($summary, 0, self::MAX_SUMMARY),
            'updated_at' => $stamp,
        ];

        if ($providerRetryAfter !== null) {
            // Written only when the provider actually said so. A failure of any other kind leaves the
            // column NULL rather than storing a wait nobody asked for.
            $values['provider_retry_after'] = DbDateTime::format($providerRetryAfter);
        }

        $this->connection->createCommand()->update(self::TABLE, $values, ['id' => $id])->execute();

        // `staged_payload` is deliberately untouched. A run that failed after the response arrived can
        // be re-imported locally, which is the difference between "try again now" and "wait a day".
    }

    public function stagedPayload(int $id): ?string
    {
        /** @var mixed $payload */
        $payload = (new Query($this->connection))
            ->select(['staged_payload'])
            ->from(self::TABLE)
            ->where(['id' => $id])
            ->scalar();

        return is_string($payload) && $payload !== '' ? $payload : null;
    }

    public function discardStagedPayload(int $id, DateTimeImmutable $now): void
    {
        $this->connection->createCommand()->update(self::TABLE, [
            'staged_payload' => null,
            'updated_at' => DbDateTime::format($now),
        ], ['id' => $id])->execute();
    }

    /**
     * Every column a reader needs, and never `staged_payload`.
     *
     * Named rather than `SELECT *` precisely so the payload cannot be added to this by accident: the
     * status endpoint polls through here, and a sixteen-megabyte column on a five-second poll is a
     * page that stops responding.
     */
    private function select(): Query
    {
        return (new Query($this->connection))
            ->select([
                'id',
                'business_day',
                'timezone',
                'status',
                'stage',
                'trigger_source',
                'orders_unchanged',
                'lease_expires_at',
                'provider_retry_after',
                'started_at',
                'fetched_at',
                'completed_at',
                'http_status',
                'response_bytes',
                'orders_received',
                'orders_inserted',
                'orders_updated',
                'orders_skipped',
                'items_imported',
                'failures',
                'error_summary',
                // Existence, not content.
                'has_staged_payload' => new Expression(
                    "(`staged_payload` IS NOT NULL AND `staged_payload` <> '')",
                ),
            ])
            ->from(self::TABLE);
    }

    private function one(Query $query): ?CaseOrderSyncRun
    {
        /** @var array<string, mixed>|null $row */
        $row = $query->limit(1)->one();

        return $row === null ? null : $this->hydrate($row);
    }

    /** @param array<array-key, mixed> $row */
    private function hydrate(array $row): CaseOrderSyncRun
    {
        return new CaseOrderSyncRun(
            id: (int) $row['id'],
            businessDay: (string) $row['business_day'],
            timezone: (string) $row['timezone'],
            // An unrecognised status is read as FAILED rather than guessed at: a run this application
            // cannot describe must not be reported as one still making progress.
            status: CaseOrderSyncStatus::tryFrom((string) $row['status']) ?? CaseOrderSyncStatus::Failed,
            stage: $this->text($row['stage'] ?? null),
            triggerSource: $this->text($row['trigger_source'] ?? null) ?? 'manual',
            startedAt: DbDateTime::parse((string) $row['started_at']),
            providerRetryAfter: $this->when($row['provider_retry_after'] ?? null),
            fetchedAt: $this->when($row['fetched_at'] ?? null),
            completedAt: $this->when($row['completed_at'] ?? null),
            leaseExpiresAt: $this->when($row['lease_expires_at'] ?? null),
            httpStatus: $this->int($row['http_status'] ?? null),
            responseBytes: $this->int($row['response_bytes'] ?? null),
            ordersReceived: (int) $row['orders_received'],
            ordersInserted: (int) $row['orders_inserted'],
            ordersUpdated: (int) $row['orders_updated'],
            ordersSkipped: (int) $row['orders_skipped'],
            ordersUnchanged: (int) $row['orders_unchanged'],
            itemsImported: (int) $row['items_imported'],
            failures: (int) $row['failures'],
            errorSummary: $this->text($row['error_summary'] ?? null),
            hasStagedPayload: (bool) ($row['has_staged_payload'] ?? false),
        );
    }

    private function when(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) && $value !== '' ? DbDateTime::parse($value) : null;
    }

    private function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
