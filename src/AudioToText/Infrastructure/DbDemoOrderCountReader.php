<?php

declare(strict_types=1);

namespace App\AudioToText\Infrastructure;

use App\AudioToText\Domain\DemoOrderCountReaderInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function array_keys;
use function is_array;
use function preg_match;

/**
 * Counts rows in the Order Testing demo-order table, by table name.
 *
 * Audio-to-Text may not name that module — `ModuleIsolationTest` matches on the namespace — so this
 * adapter addresses the **table** and nothing else, the same way {@see DbDemoOrderLinkReader} already
 * reads `order58_orders`.
 */
final readonly class DbDemoOrderCountReader implements DemoOrderCountReaderInterface
{
    private const DEMO_ORDERS = '{{%order_testing_demo_orders}}';

    public function __construct(private ConnectionInterface $connection) {}

    public function countsFor(int $storeSourceId, array $orderIds): array
    {
        $counts = [];
        $wanted = [];

        foreach ($orderIds as $orderId) {
            $counts[$orderId] = 0;

            // `audio_conversations.order_id` is free text — operators type it at upload. Only something
            // that could be an Order58 order id is worth asking the database about.
            if (preg_match('/^\d{1,19}$/', $orderId) === 1) {
                $wanted[$orderId] = true;
            }
        }

        if ($wanted === []) {
            return $counts;
        }

        $rows = (new Query($this->connection))
            ->select(['source_order_id', 'demo_count' => 'COUNT(*)'])
            ->from(self::DEMO_ORDERS)
            ->where(['store_source_id' => $storeSourceId, 'source_order_id' => array_keys($wanted)])
            ->groupBy(['source_order_id'])
            ->all();

        foreach ($rows as $row) {
            if (is_array($row)) {
                $counts[(string) $row['source_order_id']] = (int) $row['demo_count'];
            }
        }

        return $counts;
    }
}
