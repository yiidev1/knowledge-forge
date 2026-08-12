<?php

declare(strict_types=1);

namespace App\Rules\Infrastructure;

use App\Rules\Application\EnsureCommonRulesKnowledgeBaseService;
use App\Rules\Contract\RuleReadinessReaderInterface;
use App\Rules\Domain\ClassificationStatus;
use App\Rules\Domain\RuleReadinessBaseInfo;
use App\Rules\Domain\RuleReadinessItem;
use App\Rules\Domain\RuleReadinessQuery;
use App\Rules\Domain\RuleReadinessResult;
use App\Rules\Domain\RuleReadinessStatus;
use App\Rules\Domain\RuleReadinessSummary;
use Yiisoft\Db\Connection\ConnectionInterface;

use function implode;
use function is_array;
use function max;
use function trim;

/**
 * MySQL read model for Order58 rule readiness at the **synced source** grain.
 *
 * Outer set: every row in `order58_rule_records` (a successfully synced upstream rule). Catalog, global
 * projection documents, and index files are LEFT JOINed so a rule never disappears merely because later
 * pipeline stages are missing. Operational status is derived once in SQL so card counts always match filters.
 *
 * Precedence when a live global/common document exists (highest first):
 * Disabled (`is_enabled = 0`) → Ready (completed index with `openai_file_id`, not pending removal) → Failed →
 * Indexing → Processing → Queued.
 *
 * When no live global projection exists: Inactive (source soft-deactivated) → Disabled (catalog not globally
 * available) → Not materialized.
 *
 * {@see $hiddenBaseOnly}: diagnostic scope for the Global Rules base page — only sources that already have a
 * live global/common document in any KB (typically the hidden shared_rules base).
 */
final readonly class DbRuleReadinessReader implements RuleReadinessReaderInterface
{
    private const GLOBAL_TYPES = "'order58_rule_global','order58_rule_common'";

    /** Actionable-first ordering for the table. */
    private const STATUS_ORDER = "FIELD([[op_status]],'failed','indexing','processing','queued','ready',"
        . "'not_materialized','inactive','disabled')";

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function summary(string $search, bool $hiddenBaseOnly = false): RuleReadinessSummary
    {
        [$where, $params] = $this->searchWhere($search);
        $sql = $this->cte($hiddenBaseOnly)
            . ' SELECT [[op_status]], COUNT(*) [[c]] FROM [[doc]]' . $where . ' GROUP BY [[op_status]]';

        $counts = [];
        foreach ($this->connection->createCommand($sql, $params)->queryAll() as $row) {
            $counts[(string) $row['op_status']] = (int) $row['c'];
        }

        return RuleReadinessSummary::fromCounts($counts);
    }

    public function list(RuleReadinessQuery $query): RuleReadinessResult
    {
        [$where, $params] = $this->searchWhere($query->search);

        $statuses = $query->filter->statuses();
        if ($statuses !== []) {
            $where .= ($where === '' ? ' WHERE ' : ' AND ') . "[[op_status]] IN ('" . implode("','", $statuses) . "')";
        }

        $cte = $this->cte($query->hiddenBaseOnly);

        $total = (int) $this->connection
            ->createCommand($cte . ' SELECT COUNT(*) FROM [[doc]]' . $where, $params)
            ->queryScalar();

        $limit = max(1, $query->perPage);
        $offset = max(0, $query->offset());
        $sql = $cte . ' SELECT * FROM [[doc]]' . $where
            . ' ORDER BY ' . self::STATUS_ORDER . ", [[updated_at]] ASC LIMIT $limit OFFSET $offset";

        $items = [];
        foreach ($this->connection->createCommand($sql, $params)->queryAll() as $row) {
            $status = RuleReadinessStatus::from((string) $row['op_status']);
            $canonicalRaw = $row['canonical_id'];
            $docRaw = $row['doc_id'];
            $items[] = new RuleReadinessItem(
                sourceId: (int) $row['source_id'],
                documentId: $docRaw === null ? null : (int) $docRaw,
                canonicalId: $canonicalRaw === null ? null : (int) $canonicalRaw,
                title: (string) $row['title'],
                classificationLabel: $this->classificationLabel(
                    $row['classification_status'] === null ? null : (string) $row['classification_status'],
                ),
                storeName: $row['store_name'] === null || (string) $row['store_name'] === ''
                    ? null
                    : (string) $row['store_name'],
                status: $status,
                openaiFileId: $row['ready_file'] === null ? null : (string) $row['ready_file'],
                updatedAt: (string) $row['updated_at'],
                error: $status === RuleReadinessStatus::Failed && $row['error_message'] !== null
                    ? (string) $row['error_message']
                    : null,
                content: $row['rule_content'] === null ? null : (string) $row['rule_content'],
            );
        }

        return new RuleReadinessResult($items, $total, $query->page, $query->perPage);
    }

    public function hiddenBaseInfo(): ?RuleReadinessBaseInfo
    {
        $row = $this->connection->createQuery()
            ->select(['id', 'name', 'slug', 'vector_store_status'])
            ->from('{{%knowledge_bases}}')
            ->where(['slug' => EnsureCommonRulesKnowledgeBaseService::SLUG])
            ->limit(1)
            ->one();

        if (!is_array($row)) {
            return null;
        }

        return new RuleReadinessBaseInfo(
            (int) $row['id'],
            (string) $row['name'],
            (string) $row['slug'],
            (string) $row['vector_store_status'],
        );
    }

    /**
     * Shared CTE: one row per synced Order58 source rule with a derived {@see RuleReadinessStatus} value.
     */
    private function cte(bool $hiddenBaseOnly): string
    {
        $types = self::GLOBAL_TYPES;
        $hiddenFilter = $hiddenBaseOnly ? ' WHERE [[doc_id]] IS NOT NULL' : '';

        return 'WITH [[doc]] AS ('
            . ' SELECT [[base]].* FROM ('
            . ' SELECT [[r]].[[source_id]] [[source_id]], [[r]].[[title]] [[title]],'
            . ' [[c]].[[id]] [[canonical_id]], [[c]].[[classification_status]] [[classification_status]],'
            // The canonical text, falling back to the raw mirrored description when a rule has no catalog row
            // yet, so a source page can always show what the rule says.
            . ' COALESCE([[c]].[[content]], [[r]].[[description]]) [[rule_content]],'
            . ' [[d]].[[id]] [[doc_id]],'
            . ' COALESCE([[d]].[[updated_at]], [[r]].[[synced_at]], [[r]].[[updated_at]]) [[updated_at]],'
            . ' (SELECT [[st]].[[name]] FROM {{%rule_store_links}} [[l]]'
            . '   INNER JOIN {{%order58_stores}} [[st]] ON [[st]].[[source_id]] = [[l]].[[store_source_id]]'
            . "   WHERE [[l]].[[rule_catalog_rule_id]] = [[c]].[[id]] AND [[l]].[[match_status]] = 'confirmed'"
            . '   LIMIT 1) [[store_name]],'
            . ' (SELECT [[f]].[[openai_file_id]] FROM {{%document_index_files}} [[f]]'
            . "   WHERE [[f]].[[document_id]] = [[d]].[[id]] AND [[f]].[[index_status]] = 'completed'"
            . '   AND [[f]].[[openai_file_id]] IS NOT NULL AND [[f]].[[pending_removal]] = 0'
            . '   ORDER BY [[f]].[[id]] DESC LIMIT 1) [[ready_file]],'
            . ' COALESCE([[d]].[[error_message]], (SELECT [[fe]].[[last_error_message]] FROM {{%document_index_files}} [[fe]]'
            . "   WHERE [[fe]].[[document_id]] = [[d]].[[id]] AND [[fe]].[[index_status]] = 'failed'"
            . '   ORDER BY [[fe]].[[id]] DESC LIMIT 1)) [[error_message]],'
            . ' CASE'
            . '   WHEN [[d]].[[id]] IS NOT NULL AND [[d]].[[is_enabled]] = 0 THEN \'disabled\''
            . '   WHEN [[d]].[[id]] IS NOT NULL AND EXISTS ('
            . '     SELECT 1 FROM {{%document_index_files}} [[fr]] WHERE [[fr]].[[document_id]] = [[d]].[[id]]'
            . "       AND [[fr]].[[index_status]] = 'completed' AND [[fr]].[[openai_file_id]] IS NOT NULL"
            . '       AND [[fr]].[[pending_removal]] = 0) THEN \'ready\''
            . "   WHEN [[d]].[[id]] IS NOT NULL AND ([[d]].[[status]] = 'failed' OR EXISTS ("
            . '     SELECT 1 FROM {{%document_index_files}} [[ff]] WHERE [[ff]].[[document_id]] = [[d]].[[id]]'
            . "       AND [[ff]].[[index_status]] = 'failed')) THEN 'failed'"
            . "   WHEN [[d]].[[id]] IS NOT NULL AND ([[d]].[[status]] = 'indexing' OR EXISTS ("
            . '     SELECT 1 FROM {{%document_index_files}} [[fi]] WHERE [[fi]].[[document_id]] = [[d]].[[id]]'
            . "       AND [[fi]].[[index_status]] IN ('pending','in_progress'))) THEN 'indexing'"
            . "   WHEN [[d]].[[id]] IS NOT NULL AND [[d]].[[status]] = 'processing' THEN 'processing'"
            . '   WHEN [[d]].[[id]] IS NOT NULL THEN \'queued\''
            . '   WHEN [[r]].[[is_active]] = 0 THEN \'inactive\''
            . '   WHEN [[c]].[[id]] IS NOT NULL AND [[c]].[[is_globally_available]] = 0 THEN \'disabled\''
            . '   ELSE \'not_materialized\''
            . ' END [[op_status]]'
            . ' FROM {{%order58_rule_records}} [[r]]'
            . ' LEFT JOIN {{%rule_catalog_sources}} [[s]] ON [[s]].[[order58_rule_record_id]] = [[r]].[[id]]'
            . ' LEFT JOIN {{%rule_catalog_rules}} [[c]] ON [[c]].[[id]] = [[s]].[[rule_catalog_rule_id]]'
            . ' LEFT JOIN {{%documents}} [[d]] ON [[d]].[[id]] = ('
            . '   SELECT [[d2]].[[id]] FROM {{%documents}} [[d2]]'
            . '   WHERE [[c]].[[id]] IS NOT NULL'
            . '     AND [[d2]].[[source_ref]] = CAST([[c]].[[id]] AS CHAR)'
            . "     AND [[d2]].[[source_type]] IN ($types)"
            . "     AND [[d2]].[[status]] <> 'deleted'"
            . "   ORDER BY FIELD([[d2]].[[source_type]], 'order58_rule_global', 'order58_rule_common'), [[d2]].[[id]] DESC"
            . '   LIMIT 1)'
            . " ) [[base]]$hiddenFilter)";
    }

    /**
     * @return array{0: string, 1: array<non-empty-string, string>}
     */
    private function searchWhere(string $search): array
    {
        $search = trim($search);
        if ($search === '') {
            return ['', []];
        }

        return [
            ' WHERE ([[title]] LIKE :s OR CAST([[source_id]] AS CHAR) LIKE :s'
            . ' OR CAST([[canonical_id]] AS CHAR) LIKE :s OR [[store_name]] LIKE :s)',
            [':s' => '%' . $search . '%'],
        ];
    }

    /**
     * A rule with no catalog row at all reads as "Unlinked"; every known status is worded by the enum, so this
     * page and the per-store source page cannot drift apart. An unrecognised value passes through as-is.
     */
    private function classificationLabel(?string $status): string
    {
        if ($status === null || $status === '') {
            return 'Unlinked';
        }

        return ClassificationStatus::tryFrom($status)?->label() ?? $status;
    }
}
