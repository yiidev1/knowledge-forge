<?php

declare(strict_types=1);

namespace App\AudioToText\Infrastructure;

use App\AudioToText\Domain\DemoOrderLinkReaderInterface;
use App\Shared\Order58\DemoLinkStatus;
use App\Shared\Order58\DemoOrderUrl;
use Yiisoft\Db\Connection\ConnectionInterface;

use function is_array;
use function preg_match;

/**
 * Reads the mirrored order and store tables to build one page's demo links.
 *
 * Audio-to-Text may not name the Order58 module — `ModuleIsolationTest` matches on that namespace — so
 * this adapter addresses the **tables** and nothing else, the same way {@see DbAudioStoreLookup} already
 * reads `order58_stores`.
 */
final readonly class DbDemoOrderLinkReader implements DemoOrderLinkReaderInterface
{
    private const ORDERS = '{{%order58_orders}}';
    private const STORES = '{{%order58_stores}}';

    public function __construct(private ConnectionInterface $connection) {}

    public function linksFor(int $accountId, array $orderIds): array
    {
        $wanted = [];
        foreach ($orderIds as $orderId) {
            // `audio_conversations.order_id` is free text — operators type it at upload, and in this
            // database it holds everything from `999` to a real eight-digit id. Only something that
            // could be an Order58 order id is worth asking the database about; the rest are answered
            // without a query at all.
            if (preg_match('/^\d{1,19}$/', $orderId) === 1) {
                $wanted[$orderId] = true;
            }
        }

        $links = [];
        foreach ($orderIds as $orderId) {
            $links[$orderId] = DemoOrderUrl::unavailable(
                $orderId === '' ? DemoLinkStatus::NoOrderId : DemoLinkStatus::OrderNotFound,
            );
        }

        if ($wanted === []) {
            return $links;
        }

        // One query for the page. The join is on the order's own account, and the account is pinned to
        // the store being viewed, so a row can only ever describe this store's own order.
        $rows = $this->connection->createQuery()
            ->select([
                'o.source_order_id',
                'o.reservation_phone',
                's.host',
                // Counted rather than assumed: the unique key makes one row per (account, order) today,
                // but the page must be able to say "ambiguous" rather than pick, if that ever changes.
                'matches' => 'COUNT(*)',
            ])
            ->from(['o' => self::ORDERS])
            ->leftJoin(['s' => self::STORES], 's.source_id = o.account_id')
            ->where(['o.account_id' => $accountId, 'o.source_order_id' => array_keys($wanted)])
            ->groupBy(['o.source_order_id', 'o.reservation_phone', 's.host'])
            ->all();

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $orderId = (string) $row['source_order_id'];

            if ((int) $row['matches'] > 1) {
                $links[$orderId] = DemoOrderUrl::unavailable(DemoLinkStatus::Ambiguous);

                continue;
            }

            // The join is a LEFT one, so a mirrored order whose store has not been synced yet is a
            // missing store rather than a missing host — two different things to go and fix.
            if ($row['host'] === null) {
                $links[$orderId] = DemoOrderUrl::unavailable(DemoLinkStatus::StoreNotFound);

                continue;
            }

            $links[$orderId] = DemoOrderUrl::for(
                (string) $row['host'],
                (int) $row['source_order_id'],
                $row['reservation_phone'] === null ? null : (string) $row['reservation_phone'],
            );
        }

        return $links;
    }
}
