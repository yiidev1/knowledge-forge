<?php

declare(strict_types=1);

namespace App\Practice\Infrastructure;

use App\Practice\Domain\ExcludedStoreIds;
use App\Practice\Domain\StoreOption;
use App\Practice\Domain\StoreOptionsInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function is_array;

/**
 * The stores, read from the mirror by table name.
 *
 * ## The same source the Order Testing picker uses
 *
 * That page lists `order58_stores` joined to its knowledge base, and every address it builds carries
 * `source_id` — `/order-testing/store/1731`. So that is the table and that is the id, read here
 * directly rather than through another module's classes, which is how every cross-module read in this
 * application already works.
 *
 * ## Why no eligibility filter
 *
 * The picker narrows by uploaded audio, source-active and the A–Z letter, because it is answering
 * "which store shall I work on now". This is answering "which store may this topic belong to", and a
 * topic is curation: it is written before the recordings exist, and a store that is temporarily
 * inactive or has no audio yet is still a store somebody may be preparing training for. Narrowing
 * here would mean a topic could not be created until a recording happened to arrive.
 *
 * The id is still validated — {@see isOffered()} — so a topic can only name a store that is really in
 * the mirror.
 *
 * ## The one exclusion: test and demo accounts
 *
 * Those are not "stores somebody may be preparing training for", and Order58 does not mark them, so they
 * are named in configuration ({@see ExcludedStoreIds}) and left out here, in the query, for the dropdown,
 * its search and the server-side check alike. {@see namesFor()} is deliberately not narrowed: a topic
 * saved against one of them before the exclusion keeps its store, and the listing still names it.
 */
final readonly class DbStoreOptions implements StoreOptionsInterface
{
    private const TABLE = '{{%order58_stores}}';

    public function __construct(
        private ConnectionInterface $connection,
        private ExcludedStoreIds $excluded = new ExcludedStoreIds(),
    ) {}

    public function all(?int $keep = null): array
    {
        $query = (new Query($this->connection))
            ->select(['source_id', 'name', 'company'])
            ->from(self::TABLE)
            ->orderBy(['name' => SORT_ASC, 'source_id' => SORT_ASC]);

        if (!$this->excluded->isEmpty()) {
            $offered = ['not in', 'source_id', $this->excluded->ids];
            // The edited topic's own store stays in the list, so the select can show what it is.
            $query->where($keep !== null && $keep > 0 ? ['or', $offered, ['source_id' => $keep]] : $offered);
        }

        $rows = $query->all();

        $out = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $out[] = new StoreOption(
                sourceId: (int) $row['source_id'],
                name: (string) $row['name'],
                company: $row['company'] === null ? null : (string) $row['company'],
            );
        }

        return $out;
    }

    public function exists(int $storeSourceId): bool
    {
        if ($storeSourceId <= 0) {
            return false;
        }

        return (new Query($this->connection))
            ->from(self::TABLE)
            ->where(['source_id' => $storeSourceId])
            ->exists();
    }

    public function isOffered(int $storeSourceId): bool
    {
        return !$this->excluded->contains($storeSourceId) && $this->exists($storeSourceId);
    }

    public function namesFor(array $storeSourceIds): array
    {
        if ($storeSourceIds === []) {
            return [];
        }

        // One query for the whole page's worth of ids, which is what keeps the listing off N+1.
        $rows = (new Query($this->connection))
            ->select(['source_id', 'name'])
            ->from(self::TABLE)
            ->where(['source_id' => $storeSourceIds])
            ->all();

        $out = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[(int) $row['source_id']] = (string) $row['name'];
            }
        }

        return $out;
    }
}
