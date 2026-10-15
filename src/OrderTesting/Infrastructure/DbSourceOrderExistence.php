<?php

declare(strict_types=1);

namespace App\OrderTesting\Infrastructure;

use App\OrderTesting\Domain\SourceOrderExistenceInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

/**
 * Does this store have this order? One indexed lookup on the pair, and nothing else read.
 *
 * `ux_order58_orders_account_order` is exactly this question's index, so the answer costs a key probe
 * whatever the order's payload happens to contain.
 */
final readonly class DbSourceOrderExistence implements SourceOrderExistenceInterface
{
    private const ORDERS = '{{%order58_orders}}';

    public function __construct(private ConnectionInterface $connection) {}

    public function exists(int $storeSourceId, int $sourceOrderId): bool
    {
        return (new Query($this->connection))
            ->from(self::ORDERS)
            ->where([
                'account_id' => $storeSourceId,
                'source_order_id' => $sourceOrderId,
            ])
            ->exists();
    }
}
