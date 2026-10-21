<?php

declare(strict_types=1);

namespace App\AudioToText\Infrastructure;

use App\AudioToText\Domain\MirroredOrder;
use App\AudioToText\Domain\MirroredOrderReaderInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function array_keys;
use function is_array;
use function preg_match;

/**
 * Reads the mirrored order table, by table name.
 *
 * Audio-to-Text may not name the Order58 module — `ModuleIsolationTest` matches on that namespace — so
 * this adapter addresses the **table** and nothing else, the same way {@see DbDemoOrderLinkReader}
 * already reads the very same one for demo links.
 *
 * A sibling of that reader rather than an addition to it: `DemoOrderUrl` is a shared value object used
 * by three surfaces, and widening it to carry a seq would push a column this page wants onto two pages
 * that do not. One more batched query is the cheaper of the two.
 */
final readonly class DbMirroredOrderReader implements MirroredOrderReaderInterface
{
    private const ORDERS = '{{%order58_orders}}';

    public function __construct(private ConnectionInterface $connection) {}

    public function forOrders(int $accountId, array $orderIds): array
    {
        $wanted = [];

        foreach ($orderIds as $orderId) {
            // `audio_conversations.order_id` is free text — operators type it at upload, and in this
            // database it holds everything from `999` to a real eight-digit id. Only something that
            // could be an Order58 order id is worth asking the database about; the rest are answered
            // without a query at all. Nineteen digits is what a BIGINT UNSIGNED column holds, so a
            // longer string is refused here rather than silently truncated by the cast.
            if (preg_match('/^\d{1,19}$/', $orderId) === 1) {
                $wanted[$orderId] = true;
            }
        }

        if ($wanted === []) {
            return [];
        }

        /*
         * One query for the page, bounded by the account.
         *
         * No join and no grouping: the unique key is `(account_id, source_order_id)`, so this pair can
         * match at most one row and the result cannot duplicate a page row however many stores mirror
         * the same order number. `ix_order58_orders_source_order` and the unique key both cover it.
         */
        $rows = (new Query($this->connection))
            ->select(['source_order_id', 'seq'])
            ->from(self::ORDERS)
            ->where(['account_id' => $accountId, 'source_order_id' => array_keys($wanted)])
            ->all();

        $found = [];

        foreach ($rows as $row) {
            // `all()` is typed loosely enough to include objects; the guard is what the readers beside
            // this one use, rather than an annotation that only claims the shape is right.
            if (!is_array($row)) {
                continue;
            }

            $found[(string) $row['source_order_id']] = new MirroredOrder(
                $row['seq'] === null ? null : (string) $row['seq'],
            );
        }

        /*
         * Keyed by the exact strings the caller asked with.
         *
         * The database answers in its own numeric spelling — `0016800401` was asked, `16800401` comes
         * back — so the map is rebuilt against what the page actually holds, or the template would look
         * a row up by the id it has and find nothing.
         */
        $out = [];

        foreach ($orderIds as $key) {
            if (!isset($wanted[$key])) {
                continue;
            }

            $numeric = (string) (int) $key;

            if (isset($found[$numeric])) {
                $out[$key] = $found[$numeric];
            }
        }

        return $out;
    }
}
