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
                'assigned_orders' => new Expression('COUNT(a.id)'),
            ])
            ->leftJoin(['a' => self::ASSIGNMENTS], 'a.topic_id = t.id')
            ->groupBy(['t.id', 't.name', 't.description', 't.status', 't.created_at'])
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

    public function assignable(): array
    {
        $rows = (new Query($this->connection))
            ->select(['id', 'name', 'description', 'status', 'created_at'])
            ->from(self::TABLE)
            ->where(['status' => TopicStatus::Active->value])
            ->orderBy(['name' => SORT_ASC])
            ->all();

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
            ->select(['id', 'name', 'description', 'status', 'created_at'])
            ->from(self::TABLE)
            ->where(['id' => $id])
            ->one();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function create(string $name, ?string $description, TopicStatus $status, ?int $createdByAdminId): int
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        try {
            $this->connection->createCommand()->insert(self::TABLE, [
                'name' => $name,
                'name_key' => TopicInputValidator::fold($name),
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

    public function update(int $id, string $name, ?string $description): void
    {
        try {
            $this->connection->createCommand()->update(
                self::TABLE,
                [
                    'name' => $name,
                    'name_key' => TopicInputValidator::fold($name),
                    'description' => $description,
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
        );
    }
}
