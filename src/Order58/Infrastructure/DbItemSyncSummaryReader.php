<?php

declare(strict_types=1);

namespace App\Order58\Infrastructure;

use App\Order58\Domain\Items\ItemSyncSummary;
use App\Order58\Domain\Items\ItemSyncSummaryReaderInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function is_array;
use function strtoupper;

/**
 * Counts item-synchronisation states across the orders a listing is showing.
 *
 * ## One query, and it stays one
 *
 * A single grouped read over `order58_orders`. The orders page renders this once per view, above a
 * table that is already paginated, so a per-status or per-order query here would be an N+1 nobody
 * would notice until a store with real history.
 *
 * ## Why `items_synced_at` is summed per status
 *
 * `FAILED` and `PROCESSING` each mean two different things depending on whether a previous fetch
 * succeeded. A kept snapshot is comparable and counts as ready; no snapshot is not. Deriving that in
 * SQL keeps the two apart without a second read, and is what lets the bar say "comparable, but some of
 * it is from an earlier fetch" rather than having to choose between the two.
 */
final readonly class DbItemSyncSummaryReader implements ItemSyncSummaryReaderInterface
{
    private const ORDERS = '{{%order58_orders}}';

    public function __construct(private ConnectionInterface $connection) {}

    public function summaryFor(?int $storeSourceId): ItemSyncSummary
    {
        $query = (new Query($this->connection))
            ->select([
                'items_status' => 'items_status',
                'order_count' => 'COUNT(*)',
                'with_snapshot' => 'SUM(CASE WHEN items_synced_at IS NOT NULL THEN 1 ELSE 0 END)',
            ])
            ->from(self::ORDERS)
            ->groupBy(['items_status']);

        // The listing's own store filter, and nothing else. See ItemSyncSummaryReaderInterface.
        if ($storeSourceId !== null) {
            $query->where(['account_id' => $storeSourceId]);
        }

        $total = 0;
        $ready = 0;
        $notSynced = 0;
        $inProgress = 0;
        $failed = 0;
        $stale = 0;

        foreach ($query->all() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $status = $row['items_status'] === null ? '' : strtoupper((string) $row['items_status']);
            $count = (int) $row['order_count'];
            $kept = (int) $row['with_snapshot'];
            $withoutSnapshot = $count - $kept;

            $total += $count;

            switch ($status) {
                case 'SYNCED':
                case 'EMPTY':
                    // Both are complete: one has lines, the other is authoritatively empty.
                    $ready += $count;

                    break;

                case 'QUEUED':
                    $inProgress += $count;

                    break;

                case 'PROCESSING':
                    // A refresh over a kept snapshot is still comparable, and still worth flagging.
                    $ready += $kept;
                    $stale += $kept;
                    $inProgress += $withoutSnapshot;

                    break;

                case 'FAILED':
                    $ready += $kept;
                    $stale += $kept;
                    $failed += $withoutSnapshot;

                    break;

                default:
                    // PENDING, and anything a later migration adds that this does not know yet. The
                    // honest reading of an unrecognised state is "not synchronised".
                    $notSynced += $count;
            }
        }

        return new ItemSyncSummary(
            total: $total,
            ready: $ready,
            notSynced: $notSynced,
            inProgress: $inProgress,
            failed: $failed,
            stale: $stale,
        );
    }
}
