<?php

declare(strict_types=1);

namespace App\Order58\Infrastructure;

use App\Order58\Domain\StoreAudioCountsInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
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

        /** @var list<array<string, mixed>> $rows */
        $rows = (new Query($this->connection))
            ->select(['store_source_id', 'total' => 'COUNT(*)'])
            ->from(self::CONVERSATIONS)
            ->where(['store_source_id' => $sourceIds])
            ->groupBy('store_source_id')
            ->all();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['store_source_id']] = (int) $row['total'];
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
