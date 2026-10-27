<?php

declare(strict_types=1);

namespace App\Order58\Infrastructure;

use App\Order58\Domain\Items\Order58OrderItem;
use App\Order58\Domain\Items\OrderItemRepositoryInterface;
use App\Shared\Infrastructure\Db\DbDateTime;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function is_array;
use function is_string;

use const SORT_ASC;

/**
 * Original order items, in MySQL.
 *
 * Every statement goes through the query builder's parameter binding — nothing here concatenates a
 * value into SQL. That matters more than usual because one of the columns is called `desc`, a reserved
 * word: the builder quotes identifiers, so the column is reachable, but hand-written SQL would have to
 * backtick it.
 */
final readonly class DbOrder58OrderItemRepository implements OrderItemRepositoryInterface
{
    private const ITEMS = '{{%order58_order_items}}';
    private const ORDERS = '{{%order58_orders}}';

    public function __construct(private ConnectionInterface $connection) {}

    public function replace(
        int $storeAccountId,
        int $sourceOrderId,
        array $items,
        /**
         * Which holder is writing, or null when the caller is guarded some other way.
         *
         * The lease exists because the legacy integration fetched items one order at a time, from a
         * queue several workers could draw from at once: without it a slow holder could overwrite a
         * newer snapshot. The Case Order import has no such race — it claims the day's single
         * allowance before it starts, so there is exactly one importer by construction and no second
         * writer for a lease to exclude.
         *
         * Null therefore means "the caller already holds something stronger", not "skip the check
         * because it is inconvenient". The queue path still passes its owner and is still checked.
         */
        ?string $leaseOwner,
        DateTimeImmutable $now,
    ): bool {
        /** @var bool */
        return $this->connection->transaction(
            function () use ($storeAccountId, $sourceOrderId, $items, $leaseOwner, $now): bool {
                // FIRST, and inside the transaction: is the lease still ours? `FOR UPDATE` holds the
                // parent row for the duration, so a competing worker cannot claim it halfway through
                // the delete/insert below. A check placed after the writes would let a stale holder
                // destroy a newer snapshot and only then discover it had lost.
                /** @var mixed $owner */
                $owner = (new Query($this->connection))
                    ->select('items_lease_owner')
                    ->from(self::ORDERS)
                    ->where(['account_id' => $storeAccountId, 'source_order_id' => $sourceOrderId])
                    ->for('UPDATE')
                    ->scalar();

                if ($leaseOwner !== null && (!is_string($owner) || $owner !== $leaseOwner)) {
                    // Nothing deleted, nothing inserted. The caller reports a lost race.
                    return false;
                }

                $this->connection->createCommand()->delete(self::ITEMS, [
                    'store_account_id' => $storeAccountId,
                    'source_order_id' => $sourceOrderId,
                ])->execute();

                if ($items === []) {
                    // An authoritative empty order. The delete above is the whole of the write.
                    return true;
                }

                $ts = DbDateTime::format($now);
                $rows = [];

                foreach ($items as $item) {
                    $rows[] = [
                        $item->storeAccountId,
                        $item->sourceOrderId,
                        $item->sourceItemId,
                        $item->name,
                        $item->qty,
                        $item->desc,
                        $item->price,
                        $item->costPrice,
                        $item->type,
                        $item->accountId,
                        $item->userId,
                        $item->orderId,
                        $item->data,
                        $item->customerId,
                        $item->productId,
                        $item->tag,
                        $item->packIdx,
                        $item->flag,
                        $item->printIdx,
                        $item->deleted,
                        $item->freeQty,
                        $item->position,
                        $item->comparisonJson,
                        $item->contentHash,
                        $ts,
                        $ts,
                    ];
                }

                // One statement for the whole order: a five-item order is one round trip, not five.
                // `insertBatch`, not `batchInsert` — the latter does not exist on this version's
                // CommandInterface, and Psalm caught the difference before MySQL would have.
                $this->connection->createCommand()->insertBatch(
                    self::ITEMS,
                    $rows,
                    [
                        'store_account_id', 'source_order_id', 'source_item_id',
                        'name', 'qty', 'desc', 'price', 'cost_price', 'type',
                        'account_id', 'user_id', 'order_id', 'data',
                        'customer_id', 'product_id', 'tag', 'pack_idx', 'flag',
                        'print_idx', 'deleted', 'free_qty',
                        'position', 'comparison_json', 'content_hash',
                        'created_at', 'updated_at',
                    ],
                )->execute();

                return true;
            },
        );
    }

    public function forOrder(int $storeAccountId, int $sourceOrderId): array
    {
        $rows = (new Query($this->connection))
            ->select('*')
            ->from(self::ITEMS)
            // Both ids, always: an order id belonging to another merchant cannot be read here.
            ->where(['store_account_id' => $storeAccountId, 'source_order_id' => $sourceOrderId])
            // Provider order, covered by ix_o58_items_order so there is no filesort.
            ->orderBy(['position' => SORT_ASC])
            ->all();

        $items = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $items[] = $this->hydrate($row);
            }
        }

        return $items;
    }

    /** @param array<array-key, mixed> $row */
    private function hydrate(array $row): Order58OrderItem
    {
        return new Order58OrderItem(
            storeAccountId: (int) $row['store_account_id'],
            sourceOrderId: (int) $row['source_order_id'],
            sourceItemId: (int) $row['source_item_id'],
            name: (string) $row['name'],
            qty: (string) $row['qty'],
            desc: self::text($row['desc'] ?? null),
            price: self::text($row['price'] ?? null),
            costPrice: self::text($row['cost_price'] ?? null),
            type: self::text($row['type'] ?? null),
            accountId: self::number($row['account_id'] ?? null),
            userId: self::number($row['user_id'] ?? null),
            orderId: self::number($row['order_id'] ?? null),
            data: self::text($row['data'] ?? null),
            customerId: self::number($row['customer_id'] ?? null),
            productId: self::number($row['product_id'] ?? null),
            tag: self::text($row['tag'] ?? null),
            packIdx: self::number($row['pack_idx'] ?? null),
            flag: self::text($row['flag'] ?? null),
            printIdx: self::number($row['print_idx'] ?? null),
            deleted: self::number($row['deleted'] ?? null),
            freeQty: self::text($row['free_qty'] ?? null),
            position: (int) $row['position'],
            comparisonJson: (string) $row['comparison_json'],
            contentHash: (string) $row['content_hash'],
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
