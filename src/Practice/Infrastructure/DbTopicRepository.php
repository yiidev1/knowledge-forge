<?php

declare(strict_types=1);

namespace App\Practice\Infrastructure;

use App\Practice\Application\TopicInputValidator;
use App\Practice\Domain\Topic;
use App\Practice\Domain\TopicRepositoryInterface;
use App\Practice\Domain\TopicStatus;
use App\Shared\Domain\Clock\ClockInterface;
use App\Shared\Domain\Exception\ValidationException;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

use function is_array;
use function str_contains;

use const SORT_ASC;

/**
 * The topic master list.
 *
 * ## Uniqueness is the database's answer
 *
 * `create()` and `update()` do not ask whether a name is taken before writing it — they write, and turn
 * the unique-key violation into a field error. Asking first would let two requests both pass the check
 * before either inserted, and the second would get a 500 instead of "that name is already in use".
 *
 * The key is on `live_name_key`, which the migration defines as NULL once archived, so an archived name
 * frees itself and two live topics still cannot collide.
 *
 * ## Counting assignments without a query per row
 *
 * The list page shows how many orders carry each topic. That is a LEFT JOIN with a GROUP BY on the page
 * of topics being shown — one query — rather than a count per row.
 */
final readonly class DbTopicRepository implements TopicRepositoryInterface
{
    private const TABLE = '{{%practice_topics}}';
    private const ASSIGNMENTS = '{{%practice_order_topics}}';
    /** Read by name, as every cross-module read here does. */
    private const STORES = '{{%order58_stores}}';

    public function __construct(
        private ConnectionInterface $connection,
        private ClockInterface $clock,
    ) {}

    public function page(string $search, ?TopicStatus $status, int $page, int $perPage): array
    {
        $base = (new Query($this->connection))->from(['t' => self::TABLE]);

        // No status asked for means "the working list": everything except the archive.
        $base = $status === null
            ? $base->where(['<>', 't.status', TopicStatus::Archived->value])
            : $base->where(['t.status' => $status->value]);

        if ($search !== '') {
            $base = $base->andWhere(['like', 't.name', $search]);
        }

        $total = (int) (clone $base)->count();

        $rows = (clone $base)
            ->select([
                't.id',
                't.name',
                't.description',
                't.status',
                't.created_at',
                't.store_source_id',
                // Joined here rather than looked up per row: the listing shows a name for every
                // topic on the page, and a query each would be the N+1 this exists to avoid. A LEFT
                // join, so a topic whose store has left the mirror still lists — with its id, which
                // is more use than a blank.
                'store_name' => 's.name',
                'assigned_orders' => new Expression('COUNT(a.id)'),
            ])
            ->leftJoin(['a' => self::ASSIGNMENTS], 'a.topic_id = t.id')
            ->leftJoin(['s' => self::STORES], 's.source_id = t.store_source_id')
            ->groupBy([
                't.id', 't.name', 't.description', 't.status', 't.created_at',
                't.store_source_id', 's.name',
            ])
            ->orderBy(['t.name' => SORT_ASC, 't.id' => SORT_ASC])
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->all();

        $items = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $items[] = $this->hydrate($row);
            }
        }

        return ['items' => $items, 'total' => $total];
    }

    public function assignable(?int $storeSourceId = null): array
    {
        $query = (new Query($this->connection))
            ->select([
                't.id', 't.name', 't.description', 't.status', 't.created_at', 't.store_source_id',
                'store_name' => 's.name',
            ])
            ->from(['t' => self::TABLE])
            ->leftJoin(['s' => self::STORES], 's.source_id = t.store_source_id')
            ->where(['t.status' => TopicStatus::Active->value]);

        if ($storeSourceId !== null) {
            /*
             * The scope rule, in the one place a list of choices is built.
             *
             * Global topics belong to every store; a store's own belong to it alone. Written as an
             * OR on the column rather than as two queries, so the ordering below is over the whole
             * set and a store's topic sits among the global ones by name rather than after them.
             */
            $query = $query->andWhere([
                'or',
                ['t.store_source_id' => null],
                ['t.store_source_id' => $storeSourceId],
            ]);
        }

        $rows = $query->orderBy(['t.name' => SORT_ASC])->all();

        $items = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $items[] = $this->hydrate($row);
            }
        }

        return $items;
    }

    public function find(int $id): ?Topic
    {
        $row = (new Query($this->connection))
            ->select([
                't.id', 't.name', 't.description', 't.status', 't.created_at', 't.store_source_id',
                'store_name' => 's.name',
            ])
            ->from(['t' => self::TABLE])
            ->leftJoin(['s' => self::STORES], 's.source_id = t.store_source_id')
            ->where(['t.id' => $id])
            ->one();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function create(
        string $name,
        ?string $description,
        TopicStatus $status,
        ?int $createdByAdminId,
        ?int $storeSourceId = null,
    ): int {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        try {
            $this->connection->createCommand()->insert(self::TABLE, [
                'name' => $name,
                'name_key' => TopicInputValidator::fold($name),
                // Null is global. The unique key folds it to 0 so two global topics cannot share a
                // name — see the migration.
                'store_source_id' => $storeSourceId,
                'description' => $description,
                'status' => $status->value,
                'created_by_admin_id' => $createdByAdminId,
                'created_at' => $now,
                'updated_at' => $now,
            ])->execute();
        } catch (Throwable $e) {
            throw $this->duplicate($e, $name);
        }

        return (int) $this->connection->getLastInsertID();
    }

    public function update(
        int $id,
        string $name,
        ?string $description,
        ?int $storeSourceId = null,
    ): void {
        try {
            $this->connection->createCommand()->update(
                self::TABLE,
                [
                    'name' => $name,
                    'name_key' => TopicInputValidator::fold($name),
                    'description' => $description,
                    'store_source_id' => $storeSourceId,
                    'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
                ],
                ['id' => $id],
            )->execute();
        } catch (Throwable $e) {
            throw $this->duplicate($e, $name);
        }
    }

    public function changeStatus(int $id, TopicStatus $status): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $this->connection->createCommand()->update(
            self::TABLE,
            [
                'status' => $status->value,
                // Set on the way in, cleared on the way out, so the column never claims a date for a
                // topic that is not archived.
                'archived_at' => $status === TopicStatus::Archived ? $now : null,
                'updated_at' => $now,
            ],
            ['id' => $id],
        )->execute();
    }

    /**
     * How many of this topic's assignments are in a store other than the one given.
     *
     * The question a scope change has to ask before it is allowed. Restricting a topic to store A
     * when store B's orders already carry it would leave those orders pointing at a topic their store
     * is not allowed to use — the record would still read, but the rule and the data would disagree.
     *
     * Counted in SQL rather than by loading the assignments: the answer is a number, and the number
     * is all the caller needs to refuse.
     */
    public function assignmentsOutsideStore(int $id, int $storeSourceId): int
    {
        return (int) (new Query($this->connection))
            ->from(self::ASSIGNMENTS)
            ->where(['topic_id' => $id])
            ->andWhere(['<>', 'store_source_id', $storeSourceId])
            ->count();
    }

    public function assignmentCount(int $id): int
    {
        return (int) (new Query($this->connection))
            ->from(self::ASSIGNMENTS)
            ->where(['topic_id' => $id])
            ->count();
    }

    /**
     * Turn the unique-key violation into the field error it actually is.
     *
     * Anything else is rethrown untouched — swallowing an unrelated database failure as "duplicate
     * name" would hide a real fault behind a plausible message.
     */
    private function duplicate(Throwable $e, string $name): Throwable
    {
        if (str_contains($e->getMessage(), 'ux_practice_topics_live_name')) {
            return ValidationException::forField(
                'name',
                'A topic named "' . $name . '" already exists. Topic names are compared without regard '
                . 'to case or spacing.',
            );
        }

        return $e;
    }

    /** @param array<array-key, mixed> $row one row as the driver returns it, keys unproven */
    private function hydrate(array $row): Topic
    {
        return new Topic(
            id: (int) $row['id'],
            name: (string) $row['name'],
            description: $row['description'] === null ? null : (string) $row['description'],
            status: TopicStatus::from((string) $row['status']),
            assignedOrders: (int) ($row['assigned_orders'] ?? 0),
            createdAt: $row['created_at'] === null ? null : (string) $row['created_at'],
            storeSourceId: ($row['store_source_id'] ?? null) === null
                ? null
                : (int) $row['store_source_id'],
            storeName: ($row['store_name'] ?? null) === null ? null : (string) $row['store_name'],
        );
    }
}
