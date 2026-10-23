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

    /** Addressed by name for the same reason as the table above: this module may not name that one. */
    private const ATTEMPTS = '{{%order_testing_attempts}}';

    public function __construct(private ConnectionInterface $connection) {}

    public function countsFor(
        int $storeSourceId,
        array $orderIds,
        ?string $onlyActorRealm = null,
        ?int $onlyActorId = null,
    ): array {
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

        $query = (new Query($this->connection))
            ->select(['source_order_id' => 'd.source_order_id', 'demo_count' => 'COUNT(*)'])
            ->from(['d' => self::DEMO_ORDERS])
            ->where(['d.store_source_id' => $storeSourceId, 'd.source_order_id' => array_keys($wanted)])
            ->groupBy(['d.source_order_id']);

        /*
         * One practitioner's own results, decided in the query.
         *
         * An INNER join, so a demo order with no `matched_attempt_id` — Unmatched — drops out by
         * construction rather than by a condition somebody could forget. The attempts table is
         * addressed by name because this module may not name the one that owns it.
         *
         * Both halves are required together. An id without a realm would match whichever
         * administrator happens to share the number, which is the cross-realm collision this
         * codebase has already been bitten by once.
         */
        if ($onlyActorRealm !== null && $onlyActorId !== null) {
            $query = $query
                ->innerJoin(
                    ['a' => self::ATTEMPTS],
                    'a.id = d.matched_attempt_id',
                )
                ->andWhere(['a.initiated_by_type' => $onlyActorRealm, 'a.initiated_by_id' => $onlyActorId]);
        }

        $rows = $query->all();

        foreach ($rows as $row) {
            if (is_array($row)) {
                $counts[(string) $row['source_order_id']] = (int) $row['demo_count'];
            }
        }

        return $counts;
    }
}
