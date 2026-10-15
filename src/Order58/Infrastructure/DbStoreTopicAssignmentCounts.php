<?php

declare(strict_types=1);

namespace App\Order58\Infrastructure;

use App\Order58\Domain\StoreTopicAssignmentCountsInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function is_array;

/**
 * Orders carrying a topic, per store, read from the assignment table by name.
 *
 * The same by-name arrangement the readers either side of this one use: this module does not own
 * topics, and a table name in a string is not a dependency on the module that does.
 *
 * ## Why `COUNT(*)` is already per order
 *
 * `practice_order_topics` holds at most one row per `(store_source_id, source_order_id)` — the UNIQUE
 * key is the business rule — so an order cannot contribute twice however many recordings, attempts or
 * demo orders it has. Counting from any of those tables would multiply an order by the number of times
 * somebody worked on it; counting here cannot.
 *
 * ## Why the EXISTS is not decoration
 *
 * An assignment is only ever written against an order the service found in the mirror, but the mirror
 * is a copy of somebody else's table and a later resync can take a row away. An assignment left behind
 * that way describes an order this store no longer has, and a card that counted it would be describing
 * something the store page cannot show. The correlation on BOTH columns is what keeps one merchant's
 * order number from matching another merchant's assignment.
 *
 * A topic that has since been made inactive or archived still counts while it is still assigned: it is
 * what that order is being practised under, and the card describes the store as it is.
 */
final readonly class DbStoreTopicAssignmentCounts implements StoreTopicAssignmentCountsInterface
{
    private const ASSIGNMENTS = '{{%practice_order_topics}}';
    private const ORDERS = '{{%order58_orders}}';

    public function __construct(private ConnectionInterface $connection) {}

    public function assignedOrdersFor(array $sourceIds): array
    {
        if ($sourceIds === []) {
            return [];
        }

        $rows = (new Query($this->connection))
            ->select([
                'store_source_id' => 'a.store_source_id',
                'orders' => 'COUNT(*)',
            ])
            ->from(['a' => self::ASSIGNMENTS])
            ->where(['a.store_source_id' => $sourceIds])
            ->andWhere(new Expression(
                'EXISTS (SELECT 1 FROM ' . self::ORDERS . ' o'
                . ' WHERE o.account_id = a.store_source_id'
                . ' AND o.source_order_id = a.source_order_id)',
            ))
            ->groupBy('a.store_source_id')
            ->all();

        $counts = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $counts[(int) $row['store_source_id']] = (int) $row['orders'];
        }

        return $counts;
    }
}
