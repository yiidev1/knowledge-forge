<?php

declare(strict_types=1);

namespace App\Practice\Infrastructure;

use App\Practice\Domain\AssignedBy;
use App\Practice\Domain\TopicAssignment;
use App\Practice\Domain\TopicAssignmentRepositoryInterface;
use App\Practice\Domain\TopicStatus;
use App\Shared\Domain\Clock\ClockInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function is_array;

/**
 * Which topic each source order is being practised under.
 *
 * ## Every query names both ids
 *
 * `(store_source_id, source_order_id)`. An order id alone is not unique across stores, so a query that
 * omitted the store would eventually read — or write — another merchant's row. The unique key carries
 * the same pair, which is what makes an assignment an upsert and makes "one topic per source order"
 * true of the data rather than true of the code that usually writes it.
 *
 * ## A bulk write is one transaction
 *
 * `assignMany()` wraps its upserts so a failure halfway leaves nothing behind. An operator told "14 of
 * 15 saved" has no way to find the fifteenth, and clicking again would reassign the fourteen.
 */
final readonly class DbTopicAssignmentRepository implements TopicAssignmentRepositoryInterface
{
    private const TABLE = '{{%practice_order_topics}}';
    private const TOPICS = '{{%practice_topics}}';

    public function __construct(
        private ConnectionInterface $connection,
        private ClockInterface $clock,
    ) {}

    public function forOrders(int $storeSourceId, array $sourceOrderIds): array
    {
        if ($sourceOrderIds === []) {
            return [];
        }

        $rows = (new Query($this->connection))
            ->select([
                'a.source_order_id',
                'a.topic_id',
                't.name',
                't.status',
            ])
            ->from(['a' => self::TABLE])
            // INNER JOIN: the foreign key is RESTRICT, so an assignment without its topic cannot exist.
            ->innerJoin(['t' => self::TOPICS], 't.id = a.topic_id')
            ->where([
                'a.store_source_id' => $storeSourceId,
                'a.source_order_id' => $sourceOrderIds,
            ])
            ->all();

        $out = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $orderId = (int) $row['source_order_id'];

            $out[$orderId] = new TopicAssignment(
                storeSourceId: $storeSourceId,
                sourceOrderId: $orderId,
                topicId: (int) $row['topic_id'],
                topicName: (string) $row['name'],
                topicStatus: TopicStatus::from((string) $row['status']),
            );
        }

        return $out;
    }

    public function assign(int $storeSourceId, int $sourceOrderId, int $topicId, AssignedBy $by): void
    {
        $this->assignMany($storeSourceId, [$sourceOrderId], $topicId, $by);
    }

    public function assignMany(int $storeSourceId, array $sourceOrderIds, int $topicId, AssignedBy $by): int
    {
        if ($sourceOrderIds === []) {
            return 0;
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $written = 0;

        $this->connection->transaction(function () use (
            $storeSourceId,
            $sourceOrderIds,
            $topicId,
            $by,
            $now,
            &$written,
        ): void {
            foreach ($sourceOrderIds as $orderId) {
                // Upsert on the unique pair: reassignment keeps the row, and two operators writing at
                // once end with one row rather than a duplicate-key error shown to whoever was slower.
                $this->connection->createCommand()->upsert(
                    self::TABLE,
                    [
                        'store_source_id' => $storeSourceId,
                        'source_order_id' => $orderId,
                        'topic_id' => $topicId,
                        'assigned_by_type' => $by->type->value,
                        'assigned_by_id' => $by->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    [
                        'topic_id' => $topicId,
                        'assigned_by_type' => $by->type->value,
                        'assigned_by_id' => $by->id,
                        'updated_at' => $now,
                    ],
                )->execute();

                ++$written;
            }
        });

        return $written;
    }

    public function unassign(int $storeSourceId, int $sourceOrderId): void
    {
        $this->connection->createCommand()->delete(self::TABLE, [
            'store_source_id' => $storeSourceId,
            'source_order_id' => $sourceOrderId,
        ])->execute();
    }

    public function orderIdsWithTopic(int $storeSourceId, ?int $topicId): array
    {
        $query = (new Query($this->connection))
            ->select(['source_order_id'])
            ->from(self::TABLE)
            ->where(['store_source_id' => $storeSourceId]);

        if ($topicId !== null) {
            $query = $query->andWhere(['topic_id' => $topicId]);
        }

        $out = [];

        foreach ($query->column() as $id) {
            $out[] = (int) $id;
        }

        return $out;
    }
}
