<?php

declare(strict_types=1);

namespace App\Order58\Infrastructure;

use App\Order58\Domain\Orders\Order58Order;
use App\Order58\Domain\Orders\Order58OrderRepositoryInterface;
use App\Order58\Domain\Orders\OrderListQuery;
use App\Shared\Infrastructure\Db\DbDateTime;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;

use function is_array;

/**
 * Mirrored orders, in MySQL.
 *
 * Every statement goes through the query builder's parameter binding — nothing here concatenates a value
 * into SQL, including the list filters, which come from the query string.
 */
final readonly class DbOrder58OrderRepository implements Order58OrderRepositoryInterface
{
    private const TABLE = '{{%order58_orders}}';

    public function __construct(private ConnectionInterface $connection) {}

    public function hashesFor(int $accountId, array $sourceOrderIds): array
    {
        if ($sourceOrderIds === []) {
            return [];
        }

        $found = $this->connection->createQuery()
            ->select(['source_order_id', 'content_hash'])
            ->from(self::TABLE)
            ->where(['account_id' => $accountId, 'source_order_id' => $sourceOrderIds])
            ->all();

        $hashes = [];
        foreach ($found as $row) {
            // The driver's row type is array|object; only an array is a row this reads.
            if (!is_array($row)) {
                continue;
            }

            $hashes[(int) $row['source_order_id']] = (string) $row['content_hash'];
        }

        return $hashes;
    }

    public function upsert(Order58Order $order, DateTimeImmutable $now): bool
    {
        $ts = DbDateTime::format($now);

        $insert = [
            'account_id' => $order->accountId,
            'source_order_id' => $order->sourceOrderId,
            'uid' => $order->uid,
            'seq' => $order->seq,
            'customer_id' => $order->customerId,
            'user_id' => $order->userId,
            'transaction_id' => $order->transactionId,
            'type' => $order->type,
            'payment_method' => $order->paymentMethod,
            'status' => $order->status,
            'is_test' => $order->isTest ? 1 : 0,
            'frontend' => $order->frontend ? 1 : 0,
            'paid' => $order->paid ? 1 : 0,
            'reservation_phone' => $order->reservationPhone,
            'promotion_code' => $order->promotionCode,
            'address_id' => $order->addressId,
            'delivery_id' => $order->deliveryId,
            'call_id' => $order->callId,
            'print_status' => $order->printStatus,
            'pickup_delivery_at' => $order->pickupDeliveryAt,
            'timezone' => $order->timezone,
            'payload_json' => $order->payloadJson,
            'content_hash' => $order->contentHash,
            'synced_at' => $ts,
            'created_at' => $ts,
            'updated_at' => $ts,
        ] + $order->money + $order->sourceTimes;

        $update = $insert;
        unset($update['account_id'], $update['source_order_id'], $update['created_at']);

        // A phone the provider did not send must never blank one it sent last time: the field lives
        // inside `data`, and a partial record is the likeliest reason for it to be missing. Same rule the
        // store mirror applies to `host`.
        if ($order->reservationPhone === null) {
            unset($update['reservation_phone']);
        }

        // Whether the row existed is decided before the write, in the same statement's absence: MySQL's
        // affected-rows for an upsert is 1 for an insert and 2 for an update, but only when a column
        // actually changed — and this method is called only when the hash moved, so the distinction is
        // read from the table instead, which cannot be wrong.
        $existed = $this->connection->createQuery()
            ->from(self::TABLE)
            ->where(['account_id' => $order->accountId, 'source_order_id' => $order->sourceOrderId])
            ->exists();

        $this->connection->createCommand()->upsert(self::TABLE, $insert, $update)->execute();

        return !$existed;
    }

    public function page(OrderListQuery $query): array
    {
        $conditions = ['and'];

        if ($query->accountId !== null) {
            $conditions[] = ['account_id' => $query->accountId];
        }
        if ($query->sourceOrderId !== null) {
            $conditions[] = ['source_order_id' => $query->sourceOrderId];
        }
        if ($query->status !== null) {
            $conditions[] = ['status' => $query->status];
        }
        // The provider's clock is Unix seconds, so a date filter becomes a range over that, not a
        // string comparison. The end is exclusive of the next day's midnight, which is what "up to and
        // including this date" means.
        // Already `YYYY-MM-DD` by the time it reaches here, but strtotime is falsable by signature and a
        // `false` would silently become 0 and match every order ever placed.
        $from = $query->dateFrom === null ? false : strtotime($query->dateFrom . ' 00:00:00 UTC');
        if ($from !== false) {
            $conditions[] = ['>=', 'source_created_at', $from];
        }

        $to = $query->dateTo === null ? false : strtotime($query->dateTo . ' 00:00:00 UTC');
        if ($to !== false) {
            // Exclusive of the next midnight, which is what "up to and including this date" means.
            $conditions[] = ['<', 'source_created_at', $to + 86400];
        }

        $where = $conditions === ['and'] ? [] : $conditions;

        $total = (int) $this->connection->createQuery()->from(self::TABLE)->where($where)->count();

        $found = $this->connection->createQuery()
            ->select([
                'id', 'account_id', 'source_order_id', 'reservation_phone', 'type', 'payment_method',
                'status', 'is_test', 'total_amount', 'source_created_at', 'synced_at',
            ])
            ->from(self::TABLE)
            ->where($where)
            // Newest first by the provider's clock; the id breaks ties so paging is stable.
            ->orderBy(['source_created_at' => SORT_DESC, 'id' => SORT_DESC])
            ->limit(OrderListQuery::PER_PAGE)
            ->offset($query->offset())
            ->all();

        $rows = [];
        foreach ($found as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return ['rows' => $rows, 'total' => $total];
    }
}
