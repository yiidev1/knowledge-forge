<?php

declare(strict_types=1);

namespace App\Order58\Infrastructure;

use App\Order58\Domain\CallRecordings\CallRecordingRepositoryInterface;
use App\Order58\Domain\CallRecordings\NewRecording;
use App\Order58\Domain\CallRecordings\OrderCalls;
use App\Order58\Domain\CallRecordings\OrderRecordingChanges;
use App\Order58\Domain\CallRecordings\RecordingLocation;
use App\Order58\Domain\CallRecordings\StoredCallSession;
use App\Order58\Domain\CallRecordings\StoredOrderCalls;
use App\Order58\Domain\CallRecordings\StoredRecording;
use App\Shared\Infrastructure\Db\DbDateTime;
use DateTimeImmutable;
use JsonException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

use function array_values;
use function is_array;
use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;
use const SORT_ASC;

/**
 * `order58_call_sessions` and `order58_call_recordings`, read in batches and written per order.
 *
 * Every read takes the whole batch of orders at once, grouped by store, so a synchronisation of two
 * hundred orders is two reads however many sessions they hold. Writes happen one order at a time inside
 * a transaction: an order's links are either all updated or not touched, and one order that cannot be
 * written does not take the other hundred and ninety-nine with it.
 */
final readonly class DbCallRecordingRepository implements CallRecordingRepositoryInterface
{
    private const SESSIONS = '{{%order58_call_sessions}}';
    private const RECORDINGS = '{{%order58_call_recordings}}';
    private const ORDERS = '{{%order58_orders}}';

    public function __construct(private ConnectionInterface $connection) {}

    public function storedOrders(array $orders): array
    {
        if ($orders === []) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = (new Query($this->connection))
            ->select(['account_id', 'source_order_id'])
            ->from(self::ORDERS)
            ->where($this->pairs($orders, 'account_id', 'source_order_id'))
            ->all();

        $stored = [];

        foreach ($rows as $row) {
            $stored[OrderCalls::keyOf((int) $row['account_id'], (int) $row['source_order_id'])] = true;
        }

        return $stored;
    }

    public function sessionsFor(array $orders): array
    {
        if ($orders === []) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = (new Query($this->connection))
            ->select([
                'session_row' => 's.id',
                'store_source_id' => 's.store_source_id',
                'source_order_id' => 's.source_order_id',
                'call_session_id' => 's.call_session_id',
                'recording_row' => 'r.id',
                'url_hash' => 'r.url_hash',
                'position' => 'r.position',
                'delisted_at' => 'r.delisted_at',
            ])
            ->from(['s' => self::SESSIONS])
            ->leftJoin(['r' => self::RECORDINGS], 'r.session_id = s.id')
            ->where($this->pairs($orders, 's.store_source_id', 's.source_order_id'))
            ->all();

        /**
         * Keyed by order, then by provider session id — which PHP turns into an integer key, being
         * numeric. Hence the casts back to string when these are read.
         *
         * @var array<string, array<array-key, array{row: int, recordings: array<string, StoredRecording>}>> $byOrder
         */
        $byOrder = [];

        foreach ($rows as $row) {
            $key = OrderCalls::keyOf((int) $row['store_source_id'], (int) $row['source_order_id']);
            // A string, always: a numeric string used as an array key comes back as an int.
            $sessionId = (string) $row['call_session_id'];

            $byOrder[$key][$sessionId] ??= ['row' => (int) $row['session_row'], 'recordings' => []];

            if ($row['recording_row'] !== null) {
                $byOrder[$key][$sessionId]['recordings'][(string) $row['url_hash']] = new StoredRecording(
                    (int) $row['recording_row'],
                    (int) $row['position'],
                    $row['delisted_at'] === null,
                );
            }
        }

        $sessions = [];

        foreach ($byOrder as $key => $bySession) {
            foreach ($bySession as $sessionId => $stored) {
                $sessions[$key][(string) $sessionId] = new StoredCallSession(
                    $stored['row'],
                    (string) $sessionId,
                    $stored['recordings'],
                );
            }
        }

        return $sessions;
    }

    public function apply(
        int $storeSourceId,
        int $sourceOrderId,
        OrderRecordingChanges $changes,
        DateTimeImmutable $now,
    ): void {
        if ($changes->isEmpty()) {
            return;
        }

        $ts = DbDateTime::format($now);

        $this->connection->transaction(function () use ($storeSourceId, $sourceOrderId, $changes, $ts): void {
            foreach ($changes->newSessions as $sessionId => $recordings) {
                $this->connection->createCommand()->insert(self::SESSIONS, [
                    'store_source_id' => $storeSourceId,
                    'source_order_id' => $sourceOrderId,
                    'call_session_id' => (string) $sessionId,
                    'created_at' => $ts,
                    'updated_at' => $ts,
                ])->execute();

                $this->insertRecordings((int) $this->connection->getLastInsertID(), $recordings, $ts);
            }

            foreach ($changes->newRecordings as $sessionRow => $recordings) {
                $this->insertRecordings($sessionRow, $recordings, $ts);
            }

            foreach ($changes->updates as $recordingRow => $state) {
                $this->connection->createCommand()->update(
                    self::RECORDINGS,
                    [
                        'position' => $state->position,
                        // The catalog asks for this only when something changed, so a delisting is
                        // dated once, at the synchronisation that replaced the link.
                        'delisted_at' => $state->listed ? null : $ts,
                        'updated_at' => $ts,
                    ],
                    ['id' => $recordingRow],
                )->execute();
            }
        });
    }

    public function locate(int $storeSourceId, int $sourceOrderId, int $recordingId): ?RecordingLocation
    {
        $row = (new Query($this->connection))
            ->select([
                'id' => 'r.id',
                'store_source_id' => 's.store_source_id',
                'source_order_id' => 's.source_order_id',
                'call_session_id' => 's.call_session_id',
                'position' => 'r.position',
                'url' => 'r.url',
            ])
            ->from(['r' => self::RECORDINGS])
            ->innerJoin(['s' => self::SESSIONS], 's.id = r.session_id')
            ->where([
                'r.id' => $recordingId,
                's.store_source_id' => $storeSourceId,
                's.source_order_id' => $sourceOrderId,
                'r.delisted_at' => null,
            ])
            ->one();

        if (!is_array($row)) {
            return null;
        }

        return new RecordingLocation(
            (int) $row['id'],
            (int) $row['store_source_id'],
            (int) $row['source_order_id'],
            (string) $row['call_session_id'],
            (int) $row['position'],
            (string) $row['url'],
        );
    }

    public function storedCallsPage(int $afterRowId, int $limit, ?int $storeSourceId = null): array
    {
        $query = (new Query($this->connection))
            ->select([
                'id',
                'account_id',
                'source_order_id',
                // The one member the backfill needs. NULL when the payload has no `calls` at all.
                'calls' => new Expression("JSON_EXTRACT(`payload_json`, '$.calls')"),
            ])
            ->from(self::ORDERS)
            ->where(['>', 'id', $afterRowId])
            ->orderBy(['id' => SORT_ASC])
            ->limit($limit);

        if ($storeSourceId !== null) {
            $query->andWhere(['account_id' => $storeSourceId]);
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $query->all();
        $page = [];

        foreach ($rows as $row) {
            $raw = $row['calls'];

            try {
                /** @var mixed $calls */
                $calls = is_string($raw) ? json_decode($raw, true, 32, JSON_THROW_ON_ERROR) : null;
            } catch (JsonException) {
                // Unreadable is treated as absent: the backfill adds nothing for it and moves on.
                $calls = null;
            }

            $page[] = new StoredOrderCalls(
                (int) $row['id'],
                (int) $row['account_id'],
                (int) $row['source_order_id'],
                $calls,
            );
        }

        return $page;
    }

    /**
     * @param list<NewRecording> $recordings
     */
    private function insertRecordings(int $sessionRow, array $recordings, string $ts): void
    {
        foreach ($recordings as $recording) {
            $this->connection->createCommand()->insert(self::RECORDINGS, [
                'session_id' => $sessionRow,
                'position' => $recording->position,
                'url' => $recording->url,
                'url_hash' => $recording->urlHash,
                'delisted_at' => null,
                'created_at' => $ts,
                'updated_at' => $ts,
            ])->execute();
        }
    }

    /**
     * `(store = ? AND order IN (…)) OR …`, one group per store.
     *
     * Grouped rather than one row-constructor per order so each disjunct is a range over the unique
     * key's prefix, which is the shape the optimiser turns into index lookups.
     *
     * @param list<OrderCalls> $orders
     *
     * @return array<array-key, mixed>
     */
    private function pairs(array $orders, string $storeColumn, string $orderColumn): array
    {
        $byStore = [];

        foreach ($orders as $order) {
            $byStore[$order->storeSourceId][$order->sourceOrderId] = $order->sourceOrderId;
        }

        $condition = ['or'];

        foreach ($byStore as $store => $ids) {
            $condition[] = ['and', [$storeColumn => $store], [$orderColumn => array_values($ids)]];
        }

        return $condition;
    }
}
