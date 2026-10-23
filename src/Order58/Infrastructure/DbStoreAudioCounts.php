<?php

declare(strict_types=1);

namespace App\Order58\Infrastructure;

use App\Order58\Domain\StoreAudioBreakdown;
use App\Order58\Domain\StoreAudioCountsInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

use const SORT_ASC;

/**
 * Conversion counts, read straight from the audio tables.
 *
 * Counts `audio_conversations`, never `audio_transcription_jobs`: a separate Customer + Agent upload
 * is two job rows and one conversion, and the number on a store card is the number of conversions an
 * administrator made.
 *
 * Rows with `store_source_id IS NULL` — every conversion that predates store-wise audio — are counted
 * against no store, which is correct: there is no store they belong to.
 */
final readonly class DbStoreAudioCounts implements StoreAudioCountsInterface
{
    /**
     * Whether this conversation's order id names a real order **in the same store**.
     *
     * The table is addressed by name because this module owns it; the shape of the test is the one
     * `DbMirroredOrderReader` and `OrderTestingJobScope` already use.
     */
    private const LINKED_PREDICATE = "order_id REGEXP '^[1-9][0-9]{0,18}$' AND EXISTS ("
        . 'SELECT 1 FROM `order58_orders` o'
        . ' WHERE o.account_id = `audio_conversations`.`store_source_id`'
        . ' AND o.source_order_id = CAST(`audio_conversations`.`order_id` AS UNSIGNED))';

    private const CONVERSATIONS = '{{%audio_conversations}}';

    public function __construct(private ConnectionInterface $connection) {}

    public function countsFor(array $sourceIds, bool $linkedOrdersOnly = false): array
    {
        if ($sourceIds === []) {
            return [];
        }

        /**
         * One query, four numbers.
         *
         * The three type counts are conditional aggregates on the SAME grouped scan that already
         * produced the total, so adding the breakdown cost no extra round trip — which is the whole
         * point of this method existing rather than the card asking per store.
         *
         * `Expression`, not a plain string: the builder reads a bare string in `select()` as a column
         * name and quotes it, which turns `SUM(recording_type = 'MIXED')` into a syntax error rather
         * than a wrong answer.
         *
         * `recording_type` is nullable, so the three do not add up to the total. What is left over is
         * derived by {@see StoreAudioBreakdown::other()} rather than selected here, so the four values
         * can never disagree with each other.
         *
         * @var list<array<string, mixed>> $rows
         */
        $rows = (new Query($this->connection))
            ->select([
                'store_source_id',
                /*
                 * Logical orders, not recordings — the number the card shows.
                 *
                 * This expression is a deliberate copy of `GroupKey::sqlExpression()` in the
                 * Audio-to-Text module, which is what the store page groups its rows by. The two
                 * cannot share code: `ModuleIsolationTest` forbids this module from naming that one
                 * and that one from naming this, so a reader here addresses the **table** and
                 * restates the rule — the same arrangement `DbTopicAssignmentReader` already lives
                 * with for the topic scope.
                 *
                 * Both copies are tested against each other rather than trusted to stay in step:
                 * `StoreAudioOrderCountTest` asserts this number equals what
                 * `DbStoreOrderGroupRepository::countFor()` reports for the same store, so a change
                 * to either side that drifts fails there rather than on a card.
                 */
                'orders' => new Expression(
                    'COUNT(DISTINCT ' . self::groupKeySql($linkedOrdersOnly) . ')',
                ),
                'total' => 'COUNT(*)',
                'mixed' => new Expression("SUM(recording_type = 'MIXED')"),
                'caller' => new Expression("SUM(recording_type = 'CALLER')"),
                'callee' => new Expression("SUM(recording_type = 'CALLEE')"),
            ])
            ->from(self::CONVERSATIONS)
            ->where(['store_source_id' => $sourceIds])
            ->groupBy('store_source_id')
            ->all();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['store_source_id']] = new StoreAudioBreakdown(
                (int) $row['orders'],
                (int) $row['total'],
                (int) $row['mixed'],
                (int) $row['caller'],
                (int) $row['callee'],
            );
        }

        return $counts;
    }

    /**
     * The grouping the store page uses, as SQL.
     *
     * `CASE` rather than `COALESCE`, and the two prefixes rather than the bare values, because an
     * order id and a conversation's public id live in the same counted set: without the prefixes an
     * order numbered like a public id would collapse the two into one.
     *
     * When `$linked` is true a row whose order id resolves to nothing contributes `NULL`, and
     * `COUNT(DISTINCT …)` skips nulls — so the row simply does not count, which is the same thing
     * the Practice Orders page does when it declines to list it. The digits guard is the one every
     * reader of this free-text column uses before casting, and the correlation carries the store as
     * well as the id because `source_order_id` is unique only within an account.
     */
    private static function groupKeySql(bool $linked): string
    {
        $key = "CASE WHEN order_id IS NOT NULL"
            . " THEN CONCAT('order:', order_id)"
            . " ELSE CONCAT('conversation:', public_id) END";

        if (!$linked) {
            return $key;
        }

        return 'CASE WHEN ' . self::LINKED_PREDICATE . ' THEN ' . $key . ' ELSE NULL END';
    }

    public function storesWithAudio(): array
    {
        /** @var list<mixed> $ids */
        $ids = (new Query($this->connection))
            ->select('store_source_id')
            ->from(self::CONVERSATIONS)
            ->where(['not', ['store_source_id' => null]])
            ->groupBy('store_source_id')
            ->orderBy(['store_source_id' => SORT_ASC])
            ->column();

        return array_map(static fn(mixed $id): int => (int) $id, $ids);
    }
}
