<?php

declare(strict_types=1);

namespace App\AudioToText\Infrastructure;

use App\AudioToText\Domain\AudioStore;
use App\AudioToText\Domain\AudioStoreLookupInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function is_string;

/**
 * One store, read straight from the mirror.
 *
 * Audio-to-Text may not name the Order58 module — `ModuleIsolationTest` matches on that namespace
 * literally and fails the build — so it queries the mirrored tables itself, exactly as the agent
 * realm's own `DbAgentStoreDirectory` does. That is the sanctioned shape here, and the duplication is
 * deliberately kept to a lookup: searching, letter bucketing, filtering and pagination all stay in
 * Order58's own directory reader, which the store picker uses directly.
 *
 * The name comes from `knowledge_bases.name`, not `order58_stores.name`, because that is the column
 * the picker sorts, buckets and displays. Reading the other one here would show a different name on
 * the page you arrived at than on the card you clicked.
 */
final readonly class DbAudioStoreLookup implements AudioStoreLookupInterface
{
    private const STORES = '{{%order58_stores}}';
    private const KB = '{{%knowledge_bases}}';
    private const SOURCE = 'order58';

    public function __construct(private ConnectionInterface $connection) {}

    public function findBySourceId(int $sourceId): ?AudioStore
    {
        if ($sourceId <= 0) {
            return null;
        }

        /** @var array<string, mixed>|null $row */
        $row = (new Query($this->connection))
            ->select([
                'source_id' => 's.source_id',
                'name' => 'kb.name',
                'company' => 's.company',
                'active' => 'kb.source_active',
            ])
            ->from(['s' => self::STORES])
            ->innerJoin(['kb' => self::KB], 'kb.source_store_id = s.source_id')
            ->where(['kb.source_system' => self::SOURCE, 's.source_id' => $sourceId])
            ->one();

        if ($row === null) {
            return null;
        }

        return new AudioStore(
            (int) $row['source_id'],
            is_string($row['name']) ? $row['name'] : '',
            $this->nullableString($row['company'] ?? null),
            // tinyint(1) arrives as int or numeric string depending on the driver's mood.
            (int) ($row['active'] ?? 0) === 1,
        );
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
