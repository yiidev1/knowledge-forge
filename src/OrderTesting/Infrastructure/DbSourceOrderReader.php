<?php

declare(strict_types=1);

namespace App\OrderTesting\Infrastructure;

use App\OrderTesting\Application\Comparison\OrderNormalizer;
use App\OrderTesting\Domain\Comparison\NormalizedOrder;
use App\OrderTesting\Domain\SourceOrderReaderInterface;
use App\Shared\Order58\DemoLinkStatus;
use App\Shared\Order58\DemoOrderUrl;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function is_array;

/**
 * Reads `order58_orders` and `order58_stores` by table name.
 *
 * The tables belong to the Order58 module and this one does not import its classes — the same
 * arrangement `DbDemoOrderLinkReader` already uses from the audio module, for the same reason: a table
 * is a shared contract, a namespace is a dependency.
 *
 * ## The payload is the source of the comparison, not the columns
 *
 * `order58_orders` has typed columns for what the orders list filters and totals, and `payload_json`
 * for the complete original object. The comparison needs the customer block, the shipping block and the
 * line items, none of which are columns — so the payload is decoded and handed to the one normalizer
 * both sides use. Mapping the columns instead would have compared the demo's items against nothing.
 */
final readonly class DbSourceOrderReader implements SourceOrderReaderInterface
{
    private const ORDERS = '{{%order58_orders}}';
    private const STORES = '{{%order58_stores}}';

    public function __construct(
        private ConnectionInterface $connection,
        private OrderNormalizer $normalizer,
    ) {}

    public function find(int $storeSourceId, int $sourceOrderId): ?NormalizedOrder
    {
        $row = (new Query($this->connection))
            ->select(['payload_json'])
            ->from(self::ORDERS)
            // Both, always. See SourceOrderReaderInterface.
            ->where(['account_id' => $storeSourceId, 'source_order_id' => $sourceOrderId])
            ->one();

        if (!is_array($row)) {
            return null;
        }

        return $this->normalizer->fromJson(
            $row['payload_json'] === null ? null : (string) $row['payload_json'],
        );
    }

    public function demoLinkFor(int $storeSourceId, int $sourceOrderId): DemoOrderUrl
    {
        $row = (new Query($this->connection))
            ->select(['o.source_order_id', 'o.reservation_phone', 's.host'])
            ->from(['o' => self::ORDERS])
            // LEFT, so an order whose store has not been synced is a missing store rather than a
            // missing host — two different things to go and fix.
            ->leftJoin(['s' => self::STORES], 's.source_id = o.account_id')
            ->where(['o.account_id' => $storeSourceId, 'o.source_order_id' => $sourceOrderId])
            ->one();

        if (!is_array($row)) {
            return DemoOrderUrl::unavailable(DemoLinkStatus::OrderNotFound);
        }

        if ($row['host'] === null) {
            return DemoOrderUrl::unavailable(DemoLinkStatus::StoreNotFound);
        }

        return DemoOrderUrl::for(
            (string) $row['host'],
            (int) $row['source_order_id'],
            $row['reservation_phone'] === null ? null : (string) $row['reservation_phone'],
        );
    }
}
