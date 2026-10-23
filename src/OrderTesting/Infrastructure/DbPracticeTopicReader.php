<?php

declare(strict_types=1);

namespace App\OrderTesting\Infrastructure;

use App\OrderTesting\Domain\Practice\PracticeTopicReaderInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function is_string;

/**
 * The order's topic, read by table name.
 *
 * Addressed as tables rather than through the Practice module's own repositories, for the reason the
 * interface gives: neither module may name the other. One row, two indexed columns, no join beyond
 * the name lookup.
 */
final readonly class DbPracticeTopicReader implements PracticeTopicReaderInterface
{
    private const ASSIGNMENTS = '{{%practice_order_topics}}';
    private const TOPICS = '{{%practice_topics}}';

    public function __construct(private ConnectionInterface $connection) {}

    public function forOrder(int $storeSourceId, int $sourceOrderId): ?string
    {
        $name = (new Query($this->connection))
            ->select(['t.name'])
            ->from(['p' => self::ASSIGNMENTS])
            ->innerJoin(['t' => self::TOPICS], 't.id = p.topic_id')
            ->where([
                'p.store_source_id' => $storeSourceId,
                'p.source_order_id' => $sourceOrderId,
            ])
            ->scalar();

        /*
         * An archived topic still names the order.
         *
         * The assignment survives archival deliberately — it is history, not a mistake to clean up —
         * so filtering by the topic's status here would blank the context on exactly the orders
         * whose context is most worth knowing.
         */
        return is_string($name) && $name !== '' ? $name : null;
    }
}
