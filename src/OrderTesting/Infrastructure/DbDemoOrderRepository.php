<?php

declare(strict_types=1);

namespace App\OrderTesting\Infrastructure;

use App\OrderTesting\Domain\DemoOrder;
use App\OrderTesting\Domain\DemoOrderRepositoryInterface;
use App\OrderTesting\Domain\DemoOrderUpsert;
use App\Shared\Domain\Clock\ClockInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function array_fill_keys;
use function bin2hex;
use function is_array;
use function random_bytes;

use const SORT_DESC;

/**
 * Stores and reads the imported demo orders.
 *
 * ## The upsert is a read, a decision and then one write
 *
 * Not `INSERT … ON DUPLICATE KEY UPDATE`. That form cannot tell an insert from an update without
 * reading `affected_rows` and interpreting MySQL's "2 means updated" convention, and — the reason that
 * matters here — it would overwrite `matched_attempt_id` on every rescan. Attribution is written once,
 * when the row first appears, and a later scan of the same unchanged file must not move it.
 *
 * The `content_hash` short-circuit is what makes a minute-by-minute schedule free: a directory of
 * unchanged documents produces zero writes.
 */
final readonly class DbDemoOrderRepository implements DemoOrderRepositoryInterface
{
    private const TABLE = '{{%order_testing_demo_orders}}';

    public function __construct(
        private ConnectionInterface $connection,
        private ClockInterface $clock,
    ) {}

    public function upsert(DemoOrder $order, ?int $matchAttemptId): DemoOrderUpsert
    {
        $existing = (new Query($this->connection))
            ->select(['id', 'content_hash'])
            ->from(self::TABLE)
            ->where([
                'store_source_id' => $order->storeSourceId,
                'source_order_id' => $order->sourceOrderId,
                'demo_order_id' => $order->demoOrderId,
            ])
            ->one();

        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $values = $this->columns($order);

        if (!is_array($existing)) {
            $this->connection->createCommand()->insert(self::TABLE, $values + [
                'public_id' => bin2hex(random_bytes(16)),
                'store_source_id' => $order->storeSourceId,
                'source_order_id' => $order->sourceOrderId,
                'demo_order_id' => $order->demoOrderId,
                // The only moment attribution is written. See the class docblock.
                'matched_attempt_id' => $matchAttemptId,
                'imported_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ])->execute();

            return DemoOrderUpsert::Inserted;
        }

        if ((string) $existing['content_hash'] === $order->contentHash) {
            return DemoOrderUpsert::Unchanged;
        }

        // `matched_attempt_id` is deliberately absent from this list.
        $this->connection->createCommand()->update(
            self::TABLE,
            $values + ['imported_at' => $now, 'updated_at' => $now],
            ['id' => (int) $existing['id']],
        )->execute();

        return DemoOrderUpsert::Updated;
    }

    public function countsFor(int $storeSourceId, array $sourceOrderIds): array
    {
        $counts = array_fill_keys($sourceOrderIds, 0);

        if ($sourceOrderIds === []) {
            return $counts;
        }

        $rows = (new Query($this->connection))
            ->select(['source_order_id', 'demo_count' => 'COUNT(*)'])
            ->from(self::TABLE)
            // Bounded by the store as well as the ids: an order id belonging to another merchant cannot
            // be counted onto this page by typing it.
            ->where(['store_source_id' => $storeSourceId, 'source_order_id' => $sourceOrderIds])
            ->groupBy(['source_order_id'])
            ->all();

        foreach ($rows as $row) {
            if (is_array($row)) {
                $counts[(int) $row['source_order_id']] = (int) $row['demo_count'];
            }
        }

        return $counts;
    }

    public function forSourceOrder(int $storeSourceId, int $sourceOrderId): array
    {
        $rows = (new Query($this->connection))
            ->select('*')
            ->from(self::TABLE)
            ->where(['store_source_id' => $storeSourceId, 'source_order_id' => $sourceOrderId])
            ->orderBy(['demo_order_id' => SORT_DESC])
            ->all();

        $orders = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $orders[] = $this->hydrate($row);
            }
        }

        return $orders;
    }

    public function findOne(int $storeSourceId, int $sourceOrderId, int $demoOrderId): ?DemoOrder
    {
        $row = (new Query($this->connection))
            ->select('*')
            ->from(self::TABLE)
            // All three, so neither the store nor the source order can be swapped in the URL to reach
            // another merchant's demo order.
            ->where([
                'store_source_id' => $storeSourceId,
                'source_order_id' => $sourceOrderId,
                'demo_order_id' => $demoOrderId,
            ])
            ->one();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @return array<string, mixed> */
    private function columns(DemoOrder $order): array
    {
        return [
            'customer_phone' => $order->customerPhone,
            'customer_first_name' => $order->customerFirstName,
            'customer_last_name' => $order->customerLastName,
            'customer_email' => $order->customerEmail,
            'order_type' => $order->orderType,
            'payment_method' => $order->paymentMethod,
            'status' => $order->status,
            'subtotal' => $order->subtotal,
            'shipping_fee' => $order->shippingFee,
            'tax' => $order->tax,
            'tip' => $order->tip,
            'total_amount' => $order->totalAmount,
            'address' => $order->address,
            'city' => $order->city,
            'state' => $order->state,
            'postal_code' => $order->postalCode,
            'order_created_at' => $order->orderCreatedAt,
            'order_updated_at' => $order->orderUpdatedAt,
            'source_filename' => $order->sourceFilename,
            'source_mtime' => $order->sourceMtime,
            'content_hash' => $order->contentHash,
            'raw_payload' => $order->rawPayload,
        ];
    }

    /** @param array<array-key, mixed> $row */
    private function hydrate(array $row): DemoOrder
    {
        return new DemoOrder(
            storeSourceId: (int) $row['store_source_id'],
            sourceOrderId: (int) $row['source_order_id'],
            demoOrderId: (int) $row['demo_order_id'],
            customerPhone: self::text($row['customer_phone'] ?? null),
            customerFirstName: self::text($row['customer_first_name'] ?? null),
            customerLastName: self::text($row['customer_last_name'] ?? null),
            customerEmail: self::text($row['customer_email'] ?? null),
            orderType: self::text($row['order_type'] ?? null),
            paymentMethod: self::text($row['payment_method'] ?? null),
            status: self::text($row['status'] ?? null),
            subtotal: self::text($row['subtotal'] ?? null),
            shippingFee: self::text($row['shipping_fee'] ?? null),
            tax: self::text($row['tax'] ?? null),
            tip: self::text($row['tip'] ?? null),
            totalAmount: self::text($row['total_amount'] ?? null),
            address: self::text($row['address'] ?? null),
            city: self::text($row['city'] ?? null),
            state: self::text($row['state'] ?? null),
            postalCode: self::text($row['postal_code'] ?? null),
            orderCreatedAt: self::number($row['order_created_at'] ?? null),
            orderUpdatedAt: self::number($row['order_updated_at'] ?? null),
            sourceFilename: (string) ($row['source_filename'] ?? ''),
            sourceMtime: self::number($row['source_mtime'] ?? null),
            contentHash: (string) ($row['content_hash'] ?? ''),
            rawPayload: (string) ($row['raw_payload'] ?? '{}'),
            matchedAttemptId: self::number($row['matched_attempt_id'] ?? null),
        );
    }

    private static function text(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private static function number(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
