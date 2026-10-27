<?php

declare(strict_types=1);

namespace App\Order58\Infrastructure;

use App\Order58\Domain\CaseOrders\CaseOrderListItem;
use App\Order58\Domain\CaseOrders\CaseOrderListQuery;
use App\Order58\Domain\CaseOrders\CaseOrderListReaderInterface;
use App\Shared\Infrastructure\Db\DbDateTime;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

use function is_numeric;
use function is_string;

use const SORT_ASC;
use const SORT_DESC;

/**
 * The admin Case Orders listing, in two queries whatever the page size.
 *
 * ## Why the derived columns are subqueries and not joins
 *
 * Item count, total quantity, recording availability and demo availability are all one-to-many. Joined
 * in, each would multiply the rows and then need a `GROUP BY` to put them back — and the pager's
 * `COUNT(*)` would be counting the multiplied rows, so page two would skip orders. Correlated scalar
 * subqueries keep one row per order by construction, which is the property the count and the page have
 * to share.
 *
 * ## `payload_json` is never selected
 *
 * It is the whole provider document, up to a few kilobytes an order. A listing shows a summary; the
 * detail page fetches the one row it is about.
 */
final readonly class DbCaseOrderListReader implements CaseOrderListReaderInterface
{
    private const ORDERS = '{{%order58_orders}}';

    public function __construct(private ConnectionInterface $connection) {}

    public function page(CaseOrderListQuery $query, int $limit, int $offset): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->narrow($this->base(), $query)
            ->select([
                'account_id' => 'o.account_id',
                'source_order_id' => 'o.source_order_id',
                'store_name' => 's.name',
                'seq' => 'o.seq',
                'status' => 'o.status',
                'type' => 'o.type',
                'total_amount' => 'o.total_amount',
                'source_created_at' => 'o.source_created_at',
                'items_status' => 'o.items_status',
                'items_synced_at' => 'o.items_synced_at',
                'created_at' => 'o.created_at',
                'synced_at' => 'o.synced_at',
                'item_count' => new Expression($this->itemCountSql()),
                'total_qty' => new Expression($this->totalQtySql()),
                'has_recording' => new Expression($this->hasRecordingSql()),
                'has_demo' => new Expression($this->hasDemoSql()),
            ])
            ->orderBy($this->sort($query))
            ->limit($limit)
            ->offset($offset)
            ->all();

        $out = [];

        foreach ($rows as $r) {
            $out[] = new CaseOrderListItem(
                storeSourceId: (int) $r['account_id'],
                sourceOrderId: (int) $r['source_order_id'],
                storeName: is_string($r['store_name'] ?? null) ? (string) $r['store_name'] : null,
                seq: is_string($r['seq'] ?? null) && $r['seq'] !== '' ? (string) $r['seq'] : null,
                status: is_string($r['status'] ?? null) ? (string) $r['status'] : null,
                type: is_string($r['type'] ?? null) ? (string) $r['type'] : null,
                totalAmount: is_numeric($r['total_amount'] ?? null) ? (string) $r['total_amount'] : null,
                // The provider's own instant for the order. Never confused with when we stored it.
                orderedAtUnix: is_numeric($r['source_created_at'] ?? null) ? (int) $r['source_created_at'] : null,
                itemsStatus: (string) ($r['items_status'] ?? 'PENDING'),
                itemCount: (int) $r['item_count'],
                totalQuantity: (string) ($r['total_qty'] ?? '0'),
                firstImportedAt: is_string($r['created_at'] ?? null) ? DbDateTime::parse((string) $r['created_at']) : null,
                lastSyncedAt: is_string($r['synced_at'] ?? null) ? DbDateTime::parse((string) $r['synced_at']) : null,
                hasRecording: (bool) $r['has_recording'],
                hasDemoOrder: (bool) $r['has_demo'],
            );
        }

        return $out;
    }

    public function count(CaseOrderListQuery $query): int
    {
        // The same narrowing, over the same single-row-per-order shape, so the pager cannot promise
        // pages the listing does not have.
        return (int) $this->narrow($this->base(), $query)->count();
    }

    /** @return array<int, string> store source id => name, for the filter */
    public function stores(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = (new Query($this->connection))
            ->select(['source_id' => 's.source_id', 'name' => 's.name'])
            ->from(['s' => '{{%order58_stores}}'])
            ->where(new Expression('EXISTS (SELECT 1 FROM `order58_orders` `o` WHERE `o`.`account_id` = `s`.`source_id`)'))
            ->orderBy(['s.name' => SORT_ASC])
            ->all();

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row['source_id']] = (string) $row['name'];
        }

        return $out;
    }

    private function base(): Query
    {
        return (new Query($this->connection))
            ->from(['o' => self::ORDERS])
            // LEFT, because an order whose store is not mirrored is still an order and must still be
            // listed — hiding it would make the count disagree with the table.
            ->leftJoin(['s' => '{{%order58_stores}}'], 's.source_id = o.account_id');
    }

    private function narrow(Query $q, CaseOrderListQuery $query): Query
    {
        if ($query->storeSourceId !== null) {
            $q = $q->andWhere(['o.account_id' => $query->storeSourceId]);
        }

        if ($query->search !== '') {
            // Order number or seq. Both are what an operator has in front of them.
            $q = $q->andWhere(['or',
                ['o.source_order_id' => $query->search],
                ['like', 'o.seq', $query->search],
            ]);
        }

        if ($query->itemsStatus !== '') {
            $q = $q->andWhere(['o.items_status' => $query->itemsStatus]);
        }

        // Order date: the PROVIDER's instant, stored as a unix integer.
        if ($query->orderedFrom !== '') {
            $q = $q->andWhere(['>=', 'o.source_created_at', (int) strtotime($query->orderedFrom . ' 00:00:00 UTC')]);
        }

        if ($query->orderedTo !== '') {
            $q = $q->andWhere(['<=', 'o.source_created_at', (int) strtotime($query->orderedTo . ' 23:59:59 UTC')]);
        }

        // Sync date: when THIS application last stored it. A different question, and deliberately a
        // different column.
        if ($query->syncedFrom !== '') {
            $q = $q->andWhere(['>=', 'o.synced_at', $query->syncedFrom . ' 00:00:00']);
        }

        if ($query->syncedTo !== '') {
            $q = $q->andWhere(['<=', 'o.synced_at', $query->syncedTo . ' 23:59:59']);
        }

        if ($query->recording !== '') {
            $q = $q->andWhere(new Expression(
                ($query->recording === 'yes' ? '' : 'NOT ') . $this->hasRecordingSql(),
            ));
        }

        if ($query->demo !== '') {
            $q = $q->andWhere(new Expression(
                ($query->demo === 'yes' ? '' : 'NOT ') . $this->hasDemoSql(),
            ));
        }

        return $q;
    }

    /** @return array<string, int> */
    private function sort(CaseOrderListQuery $query): array
    {
        $direction = $query->descending ? SORT_DESC : SORT_ASC;

        return match ($query->sort) {
            'order' => ['o.source_order_id' => $direction],
            'store' => ['s.name' => $direction, 'o.source_order_id' => SORT_DESC],
            'synced' => ['o.synced_at' => $direction, 'o.source_order_id' => SORT_DESC],
            'items' => ['o.items_status' => $direction, 'o.source_order_id' => SORT_DESC],
            // Newest order first by default, with the id breaking ties so paging is stable.
            default => ['o.source_created_at' => $direction, 'o.source_order_id' => $direction],
        };
    }

    private function itemCountSql(): string
    {
        return '(SELECT COUNT(*) FROM `order58_order_items` `i`
                  WHERE `i`.`store_account_id` = `o`.`account_id`
                    AND `i`.`source_order_id` = `o`.`source_order_id`)';
    }

    private function totalQtySql(): string
    {
        return '(SELECT COALESCE(SUM(`i`.`qty`), 0) FROM `order58_order_items` `i`
                  WHERE `i`.`store_account_id` = `o`.`account_id`
                    AND `i`.`source_order_id` = `o`.`source_order_id`)';
    }

    /** The digits guard and the store correlation every reader of this free-text column uses. */
    private function hasRecordingSql(): string
    {
        return "EXISTS (SELECT 1 FROM `audio_conversations` `c`
                  WHERE `c`.`store_source_id` = `o`.`account_id`
                    AND `c`.`order_id` REGEXP '^[1-9][0-9]{0,18}$'
                    AND CAST(`c`.`order_id` AS UNSIGNED) = `o`.`source_order_id`)";
    }

    private function hasDemoSql(): string
    {
        return '(SELECT COUNT(*) FROM `order_testing_demo_orders` `d`
                  WHERE `d`.`store_source_id` = `o`.`account_id`
                    AND `d`.`source_order_id` = `o`.`source_order_id`) > 0';
    }
}
