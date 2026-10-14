<?php

declare(strict_types=1);

namespace App\Practice\Infrastructure;

use App\Practice\Domain\SourceOrderExistenceInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

/**
 * Which of these order ids really are orders of this store.
 *
 * Reads `order58_orders` **by table name**. That module owns the table; this one reads one fact from it
 * and deliberately gains no dependency on it — the same arrangement the audio module uses to read demo
 * order counts in the other direction.
 *
 * `account_id` is the store's Order58 source id, and the unique key on the mirror is the pair
 * `(account_id, source_order_id)`. Both are named here, always.
 */
final readonly class DbSourceOrderExistence implements SourceOrderExistenceInterface
{
    private const ORDERS = '{{%order58_orders}}';

    public function __construct(private ConnectionInterface $connection) {}

    public function existingOrderIds(int $storeSourceId, array $sourceOrderIds): array
    {
        if ($sourceOrderIds === []) {
            return [];
        }

        $rows = (new Query($this->connection))
            ->select(['source_order_id'])
            ->from(self::ORDERS)
            ->where([
                'account_id' => $storeSourceId,
                'source_order_id' => $sourceOrderIds,
            ])
            ->column();

        $out = [];

        foreach ($rows as $id) {
            $out[] = (int) $id;
        }

        return $out;
    }
}
