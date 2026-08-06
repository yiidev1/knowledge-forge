<?php

declare(strict_types=1);

namespace App\Rules\Infrastructure;

use App\Rules\Contract\RuleReportReaderInterface;
use App\Rules\Domain\RuleDetail;
use App\Rules\Domain\RuleDocumentRow;
use App\Rules\Domain\RuleEventRow;
use App\Rules\Domain\RuleReportFilter;
use App\Rules\Domain\RuleReportItem;
use App\Rules\Domain\RuleReportQuery;
use App\Rules\Domain\RuleReportResult;
use App\Rules\Domain\RuleReportSummary;
use App\Rules\Domain\RuleSourceRow;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;

use function implode;
use function is_array;
use function trim;

/**
 * MySQL-backed read model for the rules report. Each figure is one bounded aggregate query.
 */
final readonly class DbRuleReportReader implements RuleReportReaderInterface
{
    private const RULES = '{{%order58_rule_records}}';
    private const CANONICAL = '{{%rule_catalog_rules}}';
    private const SOURCES = '{{%rule_catalog_sources}}';

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function summary(): RuleReportSummary
    {
        return new RuleReportSummary(
            sourceTotal: (int) $this->connection->createQuery()->from(self::RULES)->count(),
            sourceActive: (int) $this->connection->createQuery()->from(self::RULES)->where(['is_active' => 1])->count(),
            canonicalTotal: (int) $this->connection->createQuery()->from(self::CANONICAL)->count(),
            canonicalActive: (int) $this->connection->createQuery()->from(self::CANONICAL)->where(['is_active' => 1])->count(),
            exactDuplicateSources: (int) $this->connection->createQuery()->from(self::SOURCES)->where(['relation_type' => 'exact_duplicate'])->count(),
            possibleDuplicateGroups: $this->possibleDuplicateGroups(),
            classificationByStatus: $this->classificationByStatus(),
            accountedActiveSources: $this->accountedActiveSources(),
            documentsByStatus: $this->documentsByStatus(),
            globallyAvailable: $this->globallyAvailableCount(),
        );
    }

    /**
     * Active canonical rules flagged globally available (the explicit availability flag, not the doc lifecycle).
     */
    private function globallyAvailableCount(): int
    {
        return (int) $this->connection->createQuery()
            ->from(self::CANONICAL)
            ->where(['is_active' => 1, 'is_globally_available' => 1])
            ->count();
    }

    /**
     * An EXISTS predicate over a canonical rule's live generated documents of the given source types, optionally
     * constrained to one lifecycle status. All arguments are internal constants (never user input).
     *
     * @param list<string> $sourceTypes
     */
    private function liveDocExists(array $sourceTypes, ?string $status): Expression
    {
        $inList = "'" . implode("','", $sourceTypes) . "'";
        $statusClause = $status === null ? "[[d]].[[status]] <> 'deleted'" : "[[d]].[[status]] = '" . $status . "'";

        return new Expression(
            'EXISTS (SELECT 1 FROM {{%documents}} [[d]] WHERE [[d]].[[source_ref]] = CAST([[c]].[[id]] AS CHAR)'
            . " AND [[d]].[[source_type]] IN ($inList) AND $statusClause)",
        );
    }

    public function list(RuleReportQuery $query): RuleReportResult
    {
        $where = $this->listWhere($query);

        $total = (int) $this->connection->createQuery()->from(['c' => self::CANONICAL])->where($where)->count();

        $rows = $this->connection->createQuery()
            ->select([
                'c.id',
                'c.title',
                'c.scope_type',
                'c.classification_status',
                'c.detected_store_text',
                'c.is_globally_available',
                'dup_group' => new Expression('(SELECT COUNT(*) FROM {{%rule_catalog_sources}} [[s]] WHERE [[s]].[[rule_catalog_rule_id]] = [[c]].[[id]])'),
                'matched_store' => new Expression(
                    "(SELECT [[st]].[[name]] FROM {{%rule_store_links}} [[l]]"
                    . " JOIN {{%order58_stores}} [[st]] ON [[st]].[[source_id]] = [[l]].[[store_source_id]]"
                    . " WHERE [[l]].[[rule_catalog_rule_id]] = [[c]].[[id]] AND [[l]].[[match_status]] = 'confirmed' LIMIT 1)",
                ),
                'store_doc_status' => new Expression(
                    "(SELECT [[d]].[[status]] FROM {{%documents}} [[d]]"
                    . " WHERE [[d]].[[source_ref]] = CAST([[c]].[[id]] AS CHAR)"
                    . " AND [[d]].[[source_type]] = 'order58_rule_store' AND [[d]].[[status]] <> 'deleted'"
                    . " ORDER BY FIELD([[d]].[[status]],'ready','indexing','processing','queued','failed') LIMIT 1)",
                ),
                'global_doc_status' => new Expression(
                    "(SELECT [[d]].[[status]] FROM {{%documents}} [[d]]"
                    . " WHERE [[d]].[[source_ref]] = CAST([[c]].[[id]] AS CHAR)"
                    . " AND [[d]].[[source_type]] IN ('order58_rule_global','order58_rule_common') AND [[d]].[[status]] <> 'deleted'"
                    . " ORDER BY FIELD([[d]].[[status]],'ready','indexing','processing','queued','failed') LIMIT 1)",
                ),
            ])
            ->from(['c' => self::CANONICAL])
            ->where($where)
            ->orderBy(['c.updated_at' => SORT_DESC, 'c.id' => SORT_DESC])
            ->limit($query->perPage)
            ->offset($query->offset())
            ->all();

        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $items[] = new RuleReportItem(
                canonicalId: (int) $row['id'],
                title: (string) $row['title'],
                scopeType: (string) $row['scope_type'],
                classificationStatus: (string) $row['classification_status'],
                detectedStoreText: $row['detected_store_text'] === null ? null : (string) $row['detected_store_text'],
                matchedStoreName: $row['matched_store'] === null ? null : (string) $row['matched_store'],
                duplicateGroupSize: (int) $row['dup_group'],
                storeDocumentStatus: $row['store_doc_status'] === null ? null : (string) $row['store_doc_status'],
                globalDocumentStatus: $row['global_doc_status'] === null ? null : (string) $row['global_doc_status'],
                globallyAvailable: (bool) (int) $row['is_globally_available'],
            );
        }

        return new RuleReportResult($items, $total, $query->page, $query->perPage);
    }

    public function findDetail(int $canonicalId): ?RuleDetail
    {
        $rule = $this->connection->createQuery()
            ->select(['title', 'content', 'scope_type', 'classification_status', 'detected_store_text', 'is_globally_available'])
            ->from(self::CANONICAL)
            ->where(['id' => $canonicalId])
            ->limit(1)
            ->one();

        if (!is_array($rule)) {
            return null;
        }

        [$matchedId, $matchedName] = $this->storeLink($canonicalId, 'confirmed');
        [$suggestedId, $suggestedName] = $this->storeLink($canonicalId, 'suggested');

        return new RuleDetail(
            canonicalId: $canonicalId,
            title: (string) $rule['title'],
            content: (string) $rule['content'],
            scopeType: (string) $rule['scope_type'],
            classificationStatus: (string) $rule['classification_status'],
            detectedStoreText: $rule['detected_store_text'] === null ? null : (string) $rule['detected_store_text'],
            globallyAvailable: (bool) (int) $rule['is_globally_available'],
            matchedStoreId: $matchedId,
            matchedStoreName: $matchedName,
            suggestedStoreId: $suggestedId,
            suggestedStoreName: $suggestedName,
            sources: $this->detailSources($canonicalId),
            documents: $this->detailDocuments($canonicalId),
            history: $this->detailHistory($canonicalId),
        );
    }

    /**
     * @return array{0: int|null, 1: string|null} The store source id + name for a link of the given status.
     */
    private function storeLink(int $canonicalId, string $status): array
    {
        $row = $this->connection->createQuery()
            ->select(['l.store_source_id', 'st.name'])
            ->from(['l' => '{{%rule_store_links}}'])
            ->leftJoin(['st' => '{{%order58_stores}}'], 'st.source_id = l.store_source_id')
            ->where(['l.rule_catalog_rule_id' => $canonicalId, 'l.match_status' => $status])
            ->limit(1)
            ->one();

        if (!is_array($row)) {
            return [null, null];
        }

        return [(int) $row['store_source_id'], $row['name'] === null ? null : (string) $row['name']];
    }

    /**
     * @return list<RuleSourceRow>
     */
    private function detailSources(int $canonicalId): array
    {
        $rows = $this->connection->createQuery()
            ->select(['r.source_id', 'r.title', 's.relation_type', 'r.is_active'])
            ->from(['s' => self::SOURCES])
            ->innerJoin(['r' => self::RULES], 'r.id = s.order58_rule_record_id')
            ->where(['s.rule_catalog_rule_id' => $canonicalId])
            ->orderBy(['r.source_id' => SORT_ASC])
            ->all();

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = new RuleSourceRow((int) $row['source_id'], (string) $row['title'], (string) $row['relation_type'], (bool) (int) $row['is_active']);
            }
        }

        return $out;
    }

    /**
     * @return list<RuleDocumentRow>
     */
    private function detailDocuments(int $canonicalId): array
    {
        $rows = $this->connection->createQuery()
            ->select(['kb.name', 'd.source_type', 'd.status'])
            ->from(['d' => '{{%documents}}'])
            ->innerJoin(['kb' => '{{%knowledge_bases}}'], 'kb.id = d.knowledge_base_id')
            ->where(['d.source_ref' => (string) $canonicalId])
            ->andWhere(['d.source_type' => ['order58_rule_store', 'order58_rule_global', 'order58_rule_common']])
            ->andWhere(['<>', 'd.status', 'deleted'])
            ->all();

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = new RuleDocumentRow((string) $row['name'], (string) $row['source_type'], (string) $row['status']);
            }
        }

        return $out;
    }

    /**
     * @return list<RuleEventRow>
     */
    private function detailHistory(int $canonicalId): array
    {
        $rows = $this->connection->createQuery()
            ->select(['event_type', 'old_status', 'new_status', 'message', 'admin_user_id', 'created_at'])
            ->from('{{%rule_classification_events}}')
            ->where(['rule_catalog_rule_id' => $canonicalId])
            ->orderBy(['id' => SORT_DESC])
            ->limit(50)
            ->all();

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = new RuleEventRow(
                    (string) $row['event_type'],
                    $row['old_status'] === null ? null : (string) $row['old_status'],
                    $row['new_status'] === null ? null : (string) $row['new_status'],
                    $row['message'] === null ? null : (string) $row['message'],
                    $row['admin_user_id'] === null ? null : (int) $row['admin_user_id'],
                    (string) $row['created_at'],
                );
            }
        }

        return $out;
    }

    /**
     * @return array<array-key, mixed> Yii query condition for the list filter + search.
     */
    private function listWhere(RuleReportQuery $query): array
    {
        $where = ['and', ['c.is_active' => 1]];

        if ($query->filter === RuleReportFilter::NeedsReview) {
            $where[] = ['in', 'c.classification_status', RuleReportFilter::NEEDS_REVIEW_STATUSES];
        } elseif ($query->filter === RuleReportFilter::NeedsStoreReview) {
            $where[] = ['in', 'c.classification_status', RuleReportFilter::NEEDS_STORE_REVIEW_STATUSES];
        } elseif ($query->filter === RuleReportFilter::GloballyAvailable) {
            $where[] = ['c.is_globally_available' => 1];
        } elseif ($query->filter === RuleReportFilter::NotGloballyAvailable) {
            $where[] = ['c.is_globally_available' => 0];
        } elseif ($query->filter === RuleReportFilter::Materialized) {
            $where[] = $this->liveDocExists(['order58_rule_store', 'order58_rule_global', 'order58_rule_common'], null);
        } elseif ($query->filter === RuleReportFilter::StoreProjectionReady) {
            $where[] = $this->liveDocExists(['order58_rule_store'], 'ready');
        } elseif ($query->filter === RuleReportFilter::GlobalProjectionReady) {
            $where[] = $this->liveDocExists(['order58_rule_global', 'order58_rule_common'], 'ready');
        } elseif ($query->filter === RuleReportFilter::FailedIndexing) {
            $where[] = $this->liveDocExists(['order58_rule_store', 'order58_rule_global', 'order58_rule_common'], 'failed');
        } elseif ($query->filter !== RuleReportFilter::All) {
            // ConfirmedCommon/SuggestedCommon/Ambiguous/Unmatched/Pending/Ignored map 1:1 to classification_status.
            $where[] = ['c.classification_status' => $query->filter->value];
        }

        if (trim($query->search) !== '') {
            $where[] = ['like', 'c.title', $query->search];
        }

        return $where;
    }

    /**
     * Active raw source rules that are audit-linked to a canonical rule (every active source should be).
     */
    private function accountedActiveSources(): int
    {
        return (int) $this->connection
            ->createQuery()
            ->from(['r' => self::RULES])
            ->innerJoin(['s' => self::SOURCES], 's.order58_rule_record_id = r.id')
            ->where(['r.is_active' => 1])
            ->count();
    }

    /**
     * @return array<string, int> Rule documents (store + global) grouped by document status.
     */
    private function documentsByStatus(): array
    {
        $rows = $this->connection
            ->createQuery()
            ->select(['status', 'c' => 'COUNT(*)'])
            ->from('{{%documents}}')
            ->where(['source_type' => ['order58_rule_store', 'order58_rule_global', 'order58_rule_common']])
            ->groupBy('status')
            ->all();

        $counts = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $counts[(string) $row['status']] = (int) $row['c'];
            }
        }

        return $counts;
    }

    /**
     * @return array<string, int> Active canonical rules grouped by classification_status.
     */
    private function classificationByStatus(): array
    {
        $rows = $this->connection
            ->createQuery()
            ->select(['classification_status', 'c' => 'COUNT(*)'])
            ->from(self::CANONICAL)
            ->where(['is_active' => 1])
            ->groupBy('classification_status')
            ->all();

        $counts = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $counts[(string) $row['classification_status']] = (int) $row['c'];
            }
        }

        return $counts;
    }

    /**
     * Canonical rules that share a normalized description but differ in canonical identity (i.e. different
     * titles) — surfaced as *possible* duplicates for review, never auto-merged.
     */
    private function possibleDuplicateGroups(): int
    {
        $sql = 'SELECT COUNT(*) FROM (SELECT [[description_hash]] FROM {{%rule_catalog_rules}}'
            . ' GROUP BY [[description_hash]] HAVING COUNT(DISTINCT [[canonical_hash]]) > 1) [[t]]';

        return (int) $this->connection->createCommand($sql)->queryScalar();
    }
}
