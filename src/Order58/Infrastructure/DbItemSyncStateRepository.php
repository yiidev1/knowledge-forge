<?php

declare(strict_types=1);

namespace App\Order58\Infrastructure;

use App\Order58\Domain\Items\ItemSyncState;
use App\Order58\Domain\Items\ItemSyncStateRepositoryInterface;
use App\Shared\Infrastructure\Db\DbDateTime;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

use function is_array;
use function sprintf;

use const SORT_ASC;

/**
 * Item-sync state, in the columns on `order58_orders`.
 *
 * No separate queue table: the state is strictly one row per order, orders are never deleted, and
 * `page()` selects named columns so the extra width costs the orders list nothing. A second table
 * would have held exactly as many rows with an extra join to reach them.
 */
final readonly class DbItemSyncStateRepository implements ItemSyncStateRepositoryInterface
{
    private const ORDERS = '{{%order58_orders}}';

    public function __construct(private ConnectionInterface $connection) {}

    public function claim(
        int $accountId,
        int $sourceOrderId,
        string $leaseOwner,
        array $fromStatuses,
        DateTimeImmutable $now,
        int $leaseSeconds,
    ): ?ItemSyncState {
        $stamp = DbDateTime::format($now);
        $until = DbDateTime::format($now->modify(sprintf('+%d seconds', $leaseSeconds)));

        // The precondition lives in the WHERE clause, so two callers racing produce one winner and one
        // zero-row update. `items_attempts` increments here, not on failure: a process killed
        // mid-request has still spent an attempt, which bounds a poison order.
        $claimed = $this->connection->createCommand()->update(
            self::ORDERS,
            [
                'items_status' => 'PROCESSING',
                'items_lease_owner' => $leaseOwner,
                'items_leased_until' => $until,
                'items_attempts' => new Expression('items_attempts + 1'),
                'updated_at' => $stamp,
            ],
            [
                'and',
                ['account_id' => $accountId, 'source_order_id' => $sourceOrderId],
                ['items_status' => $fromStatuses],
                // A live lease belongs to somebody else, whatever the status says.
                ['or', ['items_leased_until' => null], ['<', 'items_leased_until', $stamp]],
            ],
        )->execute();

        if ($claimed !== 1) {
            return null;
        }

        $row = (new Query($this->connection))
            ->select(['items_response_hash', 'items_attempts', 'items_synced_at'])
            ->from(self::ORDERS)
            ->where(['account_id' => $accountId, 'source_order_id' => $sourceOrderId])
            ->one();

        if (!is_array($row)) {
            return null;
        }

        return new ItemSyncState(
            accountId: $accountId,
            sourceOrderId: $sourceOrderId,
            leaseOwner: $leaseOwner,
            responseHash: $row['items_response_hash'] === null ? null : (string) $row['items_response_hash'],
            attempts: (int) $row['items_attempts'],
            // The discriminator between "never synced" and "stale snapshot kept".
            hasSnapshot: $row['items_synced_at'] !== null,
        );
    }

    public function markSynced(
        int $accountId,
        int $sourceOrderId,
        ?string $leaseOwner,
        int $itemCount,
        string $responseHash,
        ?string $responseJson,
        DateTimeImmutable $now,
    ): void {
        $stamp = DbDateTime::format($now);

        $values = [
            // EMPTY is a success with nothing in it, and is comparable. It is NOT a failure.
            'items_status' => $itemCount === 0 ? 'EMPTY' : 'SYNCED',
            'items_count' => $itemCount,
            'items_response_hash' => $responseHash,
            'items_synced_at' => $stamp,
            'items_attempts' => 0,
            'items_error' => null,
            'items_retry_after' => null,
            'items_lease_owner' => null,
            'items_leased_until' => null,
            'updated_at' => $stamp,
        ];

        if ($responseJson !== null) {
            // Omitted rather than nulled when the caller has none: the column is write-only, so
            // blanking a previous snapshot's copy would destroy the only record of it and change
            // nothing on any screen.
            $values['items_response_json'] = $responseJson;
        }

        $where = ['account_id' => $accountId, 'source_order_id' => $sourceOrderId];

        if ($leaseOwner !== null) {
            // Still ours, or this write belongs to a run that has already lost the row. A null owner
            // is a caller holding something stronger than a per-order lease — see the interface.
            $where['items_lease_owner'] = $leaseOwner;
        }

        $this->connection->createCommand()->update(self::ORDERS, $values, $where)->execute();
    }

    public function markFailed(
        int $accountId,
        int $sourceOrderId,
        string $leaseOwner,
        string $error,
        ?DateTimeImmutable $retryAfter,
        DateTimeImmutable $now,
    ): void {
        $stamp = DbDateTime::format($now);

        // items_synced_at, items_count and items_response_json are deliberately ABSENT. They describe
        // the snapshot that is still on disk, and the page needs them to say "showing items from
        // <date>, the latest refresh failed" instead of pretending the order has nothing.
        $this->connection->createCommand()->update(
            self::ORDERS,
            [
                'items_status' => 'FAILED',
                'items_error' => $error,
                'items_retry_after' => $retryAfter === null ? null : DbDateTime::format($retryAfter),
                'items_lease_owner' => null,
                'items_leased_until' => null,
                'updated_at' => $stamp,
            ],
            ['account_id' => $accountId, 'source_order_id' => $sourceOrderId, 'items_lease_owner' => $leaseOwner],
        )->execute();
    }

    public function enqueue(
        int $accountId,
        array $changedIds,
        array $unchangedIds,
        int $maxAttempts,
        DateTimeImmutable $now,
    ): int {
        $all = [...$changedIds, ...$unchangedIds];

        if ($all === []) {
            return 0;
        }

        $stamp = DbDateTime::format($now);
        $queued = 0;

        // (a) Never considered. This is the case an enqueue tied to the write would miss entirely,
        // because an unchanged order never reaches the write.
        $queued += $this->connection->createCommand()->update(
            self::ORDERS,
            ['items_status' => 'QUEUED', 'updated_at' => $stamp],
            [
                'account_id' => $accountId,
                'source_order_id' => $all,
                'items_status' => 'PENDING',
            ],
        )->execute();

        // (b) Failed, and due another go. Past the attempt cap it stays FAILED and waits for a person.
        $queued += $this->connection->createCommand()->update(
            self::ORDERS,
            ['items_status' => 'QUEUED', 'items_error' => null, 'updated_at' => $stamp],
            [
                'and',
                ['account_id' => $accountId, 'source_order_id' => $all, 'items_status' => 'FAILED'],
                ['<', 'items_attempts', $maxAttempts],
                ['or', ['items_retry_after' => null], ['<=', 'items_retry_after', $stamp]],
            ],
        )->execute();

        // (c) The parent moved, so the items may have too. Only for orders this sync actually
        // rewrote — an unchanged parent is precisely the signal that nothing needs re-fetching.
        if ($changedIds !== []) {
            $queued += $this->connection->createCommand()->update(
                self::ORDERS,
                ['items_status' => 'QUEUED', 'updated_at' => $stamp],
                [
                    'account_id' => $accountId,
                    'source_order_id' => $changedIds,
                    'items_status' => ['SYNCED', 'EMPTY'],
                ],
            )->execute();
        }

        // QUEUED and PROCESSING are absent from every predicate above, so running the same sync twice
        // queues no duplicate work.
        return $queued;
    }

    public function nextQueued(int $limit, DateTimeImmutable $now): array
    {
        $rows = (new Query($this->connection))
            ->select(['account_id', 'source_order_id'])
            ->from(self::ORDERS)
            ->where([
                'and',
                ['items_status' => 'QUEUED'],
                // A retry that is not due yet stays where it is.
                ['or', ['items_retry_after' => null], ['<=', 'items_retry_after', DbDateTime::format($now)]],
            ])
            // Oldest first, so a backlog drains in the order it arrived rather than starving.
            // Covered by ix_order58_orders_items_queue.
            ->orderBy(['id' => SORT_ASC])
            ->limit($limit)
            ->all();

        $out = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = [
                    'accountId' => (int) $row['account_id'],
                    'sourceOrderId' => (int) $row['source_order_id'],
                ];
            }
        }

        return $out;
    }

    public function statusesFor(int $accountId, array $sourceOrderIds): array
    {
        if ($sourceOrderIds === []) {
            return [];
        }

        $rows = (new Query($this->connection))
            ->select(['source_order_id', 'items_status', 'items_error'])
            ->from(self::ORDERS)
            ->where(['account_id' => $accountId, 'source_order_id' => $sourceOrderIds])
            ->all();

        $statuses = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $statuses[(int) $row['source_order_id']] = [
                'status' => $row['items_status'] === null ? 'PENDING' : (string) $row['items_status'],
                'error' => $row['items_error'] === null ? null : (string) $row['items_error'],
            ];
        }

        return $statuses;
    }

    public function expireLeases(DateTimeImmutable $now): int
    {
        $stamp = DbDateTime::format($now);

        return $this->connection->createCommand()->update(
            self::ORDERS,
            [
                'items_status' => 'QUEUED',
                // Clearing the token is the point: a stale holder's ownership check must now fail, so
                // it can never overwrite the rows the next claimant writes.
                'items_lease_owner' => null,
                'items_leased_until' => null,
                'updated_at' => $stamp,
            ],
            [
                'and',
                ['items_status' => 'PROCESSING'],
                ['<', 'items_leased_until', $stamp],
            ],
        )->execute();
    }
}
