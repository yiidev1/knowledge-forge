<?php

declare(strict_types=1);

namespace App\Order58\Infrastructure;

use App\KnowledgeBase\Domain\VectorStoreStatus;
use App\Order58\Domain\StoreDirectoryFilter;
use App\Order58\Domain\StoreDirectoryItem;
use App\Order58\Domain\StoreDirectoryQuery;
use App\Order58\Domain\StoreDirectoryReaderInterface;
use App\Order58\Domain\StoreDirectoryResult;
use App\Order58\Domain\StoreKnowledgeStatus;
use App\Shared\Infrastructure\Db\DbDateTime;
use App\Shared\Web\Support\AlphabetIndex;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\QueryInterface;

use function is_array;
use function preg_match;

/**
 * MySQL-backed admin store directory. Drives three bounded queries per page — the page rows (with correlated
 * document/knowledge counts), the matching total, and the per-letter counts — so cost is independent of
 * store volume and there is no application-side N+1. The first alphabetic character of the name is bucketed
 * to "#" for non A–Z names, matching {@see AlphabetIndex}.
 */
final readonly class DbStoreDirectoryReader implements StoreDirectoryReaderInterface
{
    private const KB = '{{%knowledge_bases}}';
    private const STORES = '{{%order58_stores}}';
    private const SOURCE = 'order58';
    private const FIRST_CHAR = 'UPPER(LEFT(TRIM(`kb`.`name`), 1))';

    // Derived-status SQL fragments, shared by the SELECT (for the card status) and the WHERE (for the
    // friendly filters), so the filter always agrees with the badge. All are correlated on `kb`.`id`.
    private const HAS_READY_DOC = "EXISTS (SELECT 1 FROM `documents` `d` WHERE `d`.`knowledge_base_id` = `kb`.`id` AND `d`.`status` = 'ready' AND `d`.`is_enabled` = 1)";
    private const HAS_IN_PROGRESS_DOC = "EXISTS (SELECT 1 FROM `documents` `d` WHERE `d`.`knowledge_base_id` = `kb`.`id` AND `d`.`is_enabled` = 1 AND `d`.`status` IN ('uploaded','queued','processing','indexing'))";
    private const HAS_FAILED_DOC = "EXISTS (SELECT 1 FROM `documents` `d` WHERE `d`.`knowledge_base_id` = `kb`.`id` AND `d`.`is_enabled` = 1 AND `d`.`status` = 'failed')";
    private const KB_PREPARING = "`kb`.`vector_store_status` IN ('pending','provisioning')";
    private const KB_FAILED = "`kb`.`vector_store_status` = 'failed'";

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function search(StoreDirectoryQuery $query): StoreDirectoryResult
    {
        $rows = $this
            ->applyLetter($this->filtered($query), $query->letter)
            ->select([
                'source_id' => 's.source_id',
                'kb_id' => 'kb.id',
                'slug' => 'kb.slug',
                'name' => 'kb.name',
                'company' => 's.company',
                'source_active' => 's.active',
                'agent_enabled' => 'kb.agent_enabled',
                'vector_store_status' => 'kb.vector_store_status',
                'last_synced' => 'kb.last_source_synced_at',
                'address' => new Expression("JSON_UNQUOTE(JSON_EXTRACT(`s`.`snapshot_json`, '$.fields.Address'))"),
                'city' => new Expression("JSON_UNQUOTE(JSON_EXTRACT(`s`.`snapshot_json`, '$.fields.City'))"),
                'state' => new Expression("JSON_UNQUOTE(JSON_EXTRACT(`s`.`snapshot_json`, '$.fields.State'))"),
                'doc_count' => new Expression(
                    '(SELECT COUNT(*) FROM `documents` `d` WHERE `d`.`knowledge_base_id` = `kb`.`id`'
                    . " AND `d`.`status` = 'ready' AND `d`.`is_enabled` = 1)",
                ),
                'knowledge_count' => new Expression(
                    '(SELECT COUNT(*) FROM `order58_knowledge_records` `kr`'
                    . ' WHERE `kr`.`store_source_id` = `s`.`source_id` AND `kr`.`active` = 1)',
                ),
                'has_in_progress' => new Expression(self::HAS_IN_PROGRESS_DOC),
                'has_failed' => new Expression(self::HAS_FAILED_DOC),
            ])
            ->orderBy(['kb.name' => SORT_ASC, 'kb.id' => SORT_ASC])
            ->limit($query->perPage)
            ->offset($query->offset())
            ->all();

        $total = (int) $this->applyLetter($this->filtered($query), $query->letter)->count();

        $items = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $items[] = $this->toItem($row);
            }
        }

        return new StoreDirectoryResult(
            items: $items,
            total: $total,
            letterCounts: $this->letterCounts($query),
            page: $query->page,
            perPage: $query->perPage,
        );
    }

    /**
     * Base query with the source-system, status filter and search applied — but not the letter, so it also
     * backs the alphabet counts.
     */
    private function filtered(StoreDirectoryQuery $query): QueryInterface
    {
        $q = $this->connection
            ->createQuery()
            ->from(['kb' => self::KB])
            ->innerJoin(['s' => self::STORES], 's.source_id = kb.source_store_id')
            ->where(['kb.source_system' => self::SOURCE]);

        $this->applyFilter($q, $query->filter);
        $this->applySearch($q, $query->search);

        if ($query->chatReadyOnly) {
            $q->andWhere(['kb.vector_store_status' => VectorStoreStatus::Ready->value])
                ->andWhere(new Expression(
                    'EXISTS (SELECT 1 FROM `documents` `d` WHERE `d`.`knowledge_base_id` = `kb`.`id`'
                    . " AND `d`.`status` = 'ready' AND `d`.`is_enabled` = 1)",
                ));
        }

        return $q;
    }

    private function applyFilter(QueryInterface $q, StoreDirectoryFilter $filter): void
    {
        // The friendly filters mirror the StoreKnowledgeStatus priority exactly, so a filtered list, its
        // total and its letter counts all agree with the badge each card shows.
        $ready = self::HAS_READY_DOC;
        $preparing = '(' . self::HAS_IN_PROGRESS_DOC . ' OR ' . self::KB_PREPARING . ')';
        $failed = '(' . self::HAS_FAILED_DOC . ' OR ' . self::KB_FAILED . ')';

        match ($filter) {
            StoreDirectoryFilter::All => null,
            StoreDirectoryFilter::Ready => $q->andWhere(new Expression($ready)),
            StoreDirectoryFilter::Processing => $q->andWhere(new Expression("NOT $ready AND $preparing")),
            StoreDirectoryFilter::Failed => $q->andWhere(new Expression("NOT $ready AND NOT $preparing AND $failed")),
            StoreDirectoryFilter::NoKnowledge => $q->andWhere(new Expression("NOT $ready AND NOT $preparing AND NOT $failed")),
        };
    }

    private function applySearch(QueryInterface $q, string $search): void
    {
        if ($search === '') {
            return;
        }

        $q->andWhere([
            'or',
            ['like', 'kb.name', $search],
            ['like', 's.company', $search],
            ['like', new Expression("JSON_UNQUOTE(JSON_EXTRACT(`s`.`snapshot_json`, '$.fields.City'))"), $search],
            ['like', new Expression("JSON_UNQUOTE(JSON_EXTRACT(`s`.`snapshot_json`, '$.fields.Address'))"), $search],
            ['like', new Expression('CAST(`s`.`source_id` AS CHAR)'), $search],
        ]);
    }

    private function applyLetter(QueryInterface $q, string $letter): QueryInterface
    {
        if ($letter === AlphabetIndex::ALL) {
            return $q;
        }
        if ($letter === AlphabetIndex::OTHER) {
            return $q->andWhere(new Expression(self::FIRST_CHAR . " NOT REGEXP '^[A-Z]$'"));
        }
        if (preg_match('/^[A-Z]$/', $letter) === 1) {
            return $q->andWhere(new Expression(self::FIRST_CHAR . ' = :letter', [':letter' => $letter]));
        }

        return $q;
    }

    /**
     * @return array<string, int>
     */
    private function letterCounts(StoreDirectoryQuery $query): array
    {
        $rows = $this->filtered($query)
            ->select(['letter' => new Expression(self::FIRST_CHAR), 'cnt' => new Expression('COUNT(*)')])
            ->groupBy(new Expression(self::FIRST_CHAR))
            ->all();

        $counts = [AlphabetIndex::ALL => 0];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $raw = (string) ($row['letter'] ?? '');
            $bucket = preg_match('/^[A-Z]$/', $raw) === 1 ? $raw : AlphabetIndex::OTHER;
            $count = (int) ($row['cnt'] ?? 0);
            $counts[$bucket] = ($counts[$bucket] ?? 0) + $count;
            $counts[AlphabetIndex::ALL] += $count;
        }

        return $counts;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function toItem(array $row): StoreDirectoryItem
    {
        $vectorStoreStatus = VectorStoreStatus::tryFrom((string) ($row['vector_store_status'] ?? '')) ?? VectorStoreStatus::Pending;
        $readyDocuments = (int) ($row['doc_count'] ?? 0);

        return new StoreDirectoryItem(
            sourceId: (int) $row['source_id'],
            knowledgeBaseId: (int) $row['kb_id'],
            slug: (string) $row['slug'],
            name: (string) $row['name'],
            company: $this->nullableString($row['company'] ?? null),
            address: $this->nullableString($row['address'] ?? null),
            city: $this->nullableString($row['city'] ?? null),
            state: $this->nullableString($row['state'] ?? null),
            sourceActive: (bool) (int) ($row['source_active'] ?? 0),
            agentEnabled: (bool) (int) ($row['agent_enabled'] ?? 0),
            vectorStoreStatus: $vectorStoreStatus,
            enabledReadyDocumentCount: $readyDocuments,
            knowledgeRecordCount: (int) ($row['knowledge_count'] ?? 0),
            lastSourceSyncedAt: DbDateTime::parseNullable($row['last_synced'] === null ? null : (string) $row['last_synced']),
            knowledgeStatus: StoreKnowledgeStatus::fromFlags(
                hasReadyDocument: $readyDocuments > 0,
                hasDocumentInProgress: (bool) (int) ($row['has_in_progress'] ?? 0),
                hasFailedDocument: (bool) (int) ($row['has_failed'] ?? 0),
                vectorStoreStatus: $vectorStoreStatus,
            ),
        );
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $string = (string) $value;

        return $string === '' ? null : $string;
    }
}
