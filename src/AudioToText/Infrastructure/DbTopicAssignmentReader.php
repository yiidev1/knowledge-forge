<?php

declare(strict_types=1);

namespace App\AudioToText\Infrastructure;

use App\AudioToText\Domain\AssignedTopic;
use App\AudioToText\Domain\TopicAssignmentReaderInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function array_values;
use function is_array;
use function preg_match;

use const SORT_ASC;

/**
 * Reads the Practice Orders topic tables, by table name.
 *
 * The same arrangement {@see DbDemoOrderCountReader} uses for the demo-order tables: this module may
 * not name the one that owns them, and a name in a string is not a dependency.
 *
 * `audio_conversations.order_id` is operator-typed free text — in this database it holds everything
 * from `999` to a real eight-digit id — so a row whose order id is not a plain number is skipped
 * before anything is cast. The same guard the demo-order readers use, for the same reason.
 */
final readonly class DbTopicAssignmentReader implements TopicAssignmentReaderInterface
{
    private const ASSIGNMENTS = '{{%practice_order_topics}}';
    private const TOPICS = '{{%practice_topics}}';

    public function __construct(private ConnectionInterface $connection) {}

    public function forOrders(int $storeSourceId, array $orderIds): array
    {
        // `array-key`, not `string`: PHP turns a numeric string key into an int on the way in, so the
        // keys of this map are ints however carefully the caller typed its list. That is why the loop
        // at the bottom casts them back rather than trusting the declared parameter type.
        /** @var array<array-key, int> $numeric the id as the caller asked it => the number it denotes */
        $numeric = [];

        foreach ($orderIds as $id) {
            if (preg_match('/\A[1-9][0-9]{0,18}\z/', $id) === 1) {
                $numeric[$id] = (int) $id;
            }
        }

        if ($numeric === []) {
            return [];
        }

        $rows = (new Query($this->connection))
            ->select(['a.source_order_id', 't.id', 't.name', 't.status', 't.description'])
            ->from(['a' => self::ASSIGNMENTS])
            ->innerJoin(['t' => self::TOPICS], 't.id = a.topic_id')
            ->where([
                'a.store_source_id' => $storeSourceId,
                'a.source_order_id' => array_values($numeric),
            ])
            ->all();

        $byNumber = [];

        foreach ($rows as $row) {
            // `all()` is typed loosely enough to include objects; the guard is what the readers
            // beside this one use, rather than an annotation that only claims the shape is right.
            if (!is_array($row)) {
                continue;
            }

            $byNumber[(int) $row['source_order_id']] = new AssignedTopic(
                id: (int) $row['id'],
                name: (string) $row['name'],
                // The stored status, carried as it is. The row decides what to say about it; this
                // decides nothing, so the screen cannot drift from the table.
                status: (string) $row['status'],
                description: $row['description'] === null ? null : (string) $row['description'],
            );
        }

        // Keyed by the exact strings the caller asked with, so the template can look a row up by the
        // order id it already holds without re-deciding what a number is.
        $out = [];

        foreach ($numeric as $raw => $number) {
            if (isset($byNumber[$number])) {
                $out[(string) $raw] = $byNumber[$number];
            }
        }

        return $out;
    }

    public function assignableTopics(): array
    {
        $rows = (new Query($this->connection))
            ->select(['id', 'name', 'description'])
            ->from(self::TOPICS)
            ->where(['status' => AssignedTopic::ACTIVE])
            ->orderBy(['name' => SORT_ASC])
            ->all();

        $out = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $out[] = new AssignedTopic(
                id: (int) $row['id'],
                name: (string) $row['name'],
                status: AssignedTopic::ACTIVE,
                description: $row['description'] === null ? null : (string) $row['description'],
            );
        }

        return $out;
    }
}
