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
    private const CONVERSATIONS = '{{%audio_conversations}}';

    public function __construct(private ConnectionInterface $connection) {}

    public function countsFor(array $sourceIds): array
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
                (int) $row['total'],
                (int) $row['mixed'],
                (int) $row['caller'],
                (int) $row['callee'],
            );
        }

        return $counts;
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
