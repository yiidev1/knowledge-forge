<?php

declare(strict_types=1);

namespace App\Rules\Infrastructure;

use App\Rules\Application\EnsureCommonRulesKnowledgeBaseService;
use App\Rules\Contract\RuleReadinessReaderInterface;
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
 * MySQL read model for rule-document readiness. The operational status of every materialized rule document is
 * computed once, in SQL, from the durable index-file snapshot — never from `documents.status` alone — so there is
 * no N+1 and the card counts always match the filtered table.
 *
 * Precedence (highest first): Disabled (`is_enabled = 0`) → Ready (a completed index file with an `openai_file_id`
 * exists, even if a newer reindex is queued/failed) → Failed → Indexing → Processing → Queued. Soft-deleted
 * (retired) documents are excluded — they are no longer operational copies.
 */
final readonly class DbRuleReadinessReader implements RuleReadinessReaderInterface
{
    /** Store rule documents plus both global/common projections of the hidden base. */
    private const RULE_TYPES = "'order58_rule_store','order58_rule_global','order58_rule_common'";
    private const HIDDEN_TYPES = "'order58_rule_global','order58_rule_common'";

    /** Actionable-first ordering for the table. */
    private const STATUS_ORDER = "FIELD([[op_status]],'failed','indexing','processing','queued','ready','disabled')";

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

        // LIMIT/OFFSET are integers we control (never user text), so inlining is injection-safe and avoids
        // PDO prepare-mode differences on bound LIMIT placeholders.
        $limit = max(1, $query->perPage);
        $offset = max(0, $query->offset());
        $sql = $cte . ' SELECT * FROM [[doc]]' . $where
            . ' ORDER BY ' . self::STATUS_ORDER . ", [[updated_at]] ASC LIMIT $limit OFFSET $offset";

        $items = [];
        foreach ($this->connection->createCommand($sql, $params)->queryAll() as $row) {
            $ref = (string) $row['source_ref'];
            $status = RuleReadinessStatus::from((string) $row['op_status']);
            $items[] = new RuleReadinessItem(
                documentId: (int) $row['doc_id'],
                // source_ref is the canonical rule id (a numeric string); guard without ext-ctype.
                canonicalId: $ref !== '' && $ref === (string) (int) $ref ? (int) $ref : null,
                title: (string) $row['title'],
                isStoreSpecific: $row['source_type'] === 'order58_rule_store',
                storeName: $row['store_name'] === null ? null : (string) $row['store_name'],
                status: $status,
                openaiFileId: $row['ready_file'] === null ? null : (string) $row['ready_file'],
                updatedAt: (string) $row['updated_at'],
                error: $status === RuleReadinessStatus::Failed && $row['error_message'] !== null ? (string) $row['error_message'] : null,
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
     * The shared CTE that derives one operational status per materialized rule document. Table/column identifiers
     * only; the only bound value is the search term (added by callers).
     */
    private function cte(bool $hiddenBaseOnly): string
    {
        $types = $hiddenBaseOnly ? self::HIDDEN_TYPES : self::RULE_TYPES;

        return 'WITH [[doc]] AS ('
            . ' SELECT [[d]].[[id]] [[doc_id]], [[d]].[[source_ref]] [[source_ref]], [[d]].[[title]] [[title]],'
            . ' [[d]].[[source_type]] [[source_type]], [[d]].[[updated_at]] [[updated_at]], [[st]].[[name]] [[store_name]],'
            . ' (SELECT [[f]].[[openai_file_id]] FROM {{%document_index_files}} [[f]]'
            . "   WHERE [[f]].[[document_id]] = [[d]].[[id]] AND [[f]].[[index_status]] = 'completed'"
            . '   AND [[f]].[[openai_file_id]] IS NOT NULL ORDER BY [[f]].[[id]] DESC LIMIT 1) [[ready_file]],'
            . ' COALESCE([[d]].[[error_message]], (SELECT [[fe]].[[last_error_message]] FROM {{%document_index_files}} [[fe]]'
            . "   WHERE [[fe]].[[document_id]] = [[d]].[[id]] AND [[fe]].[[index_status]] = 'failed'"
            . '   ORDER BY [[fe]].[[id]] DESC LIMIT 1)) [[error_message]],'
            . ' CASE'
            . '   WHEN [[d]].[[is_enabled]] = 0 THEN \'disabled\''
            . '   WHEN EXISTS (SELECT 1 FROM {{%document_index_files}} [[fr]] WHERE [[fr]].[[document_id]] = [[d]].[[id]]'
            . "     AND [[fr]].[[index_status]] = 'completed' AND [[fr]].[[openai_file_id]] IS NOT NULL) THEN 'ready'"
            . "   WHEN [[d]].[[status]] = 'failed' OR EXISTS (SELECT 1 FROM {{%document_index_files}} [[ff]]"
            . "     WHERE [[ff]].[[document_id]] = [[d]].[[id]] AND [[ff]].[[index_status]] = 'failed') THEN 'failed'"
            . "   WHEN [[d]].[[status]] = 'indexing' OR EXISTS (SELECT 1 FROM {{%document_index_files}} [[fi]]"
            . "     WHERE [[fi]].[[document_id]] = [[d]].[[id]] AND [[fi]].[[index_status]] IN ('pending','in_progress')) THEN 'indexing'"
            . "   WHEN [[d]].[[status]] = 'processing' THEN 'processing'"
            . "   ELSE 'queued'"
            . ' END [[op_status]]'
            . ' FROM {{%documents}} [[d]]'
            . ' LEFT JOIN {{%knowledge_bases}} [[kb]] ON [[kb]].[[id]] = [[d]].[[knowledge_base_id]]'
            . ' LEFT JOIN {{%order58_stores}} [[st]] ON [[st]].[[source_id]] = [[kb]].[[source_store_id]]'
            . " WHERE [[d]].[[source_type]] IN ($types) AND [[d]].[[status]] <> 'deleted')";
    }

    /**
     * @return array{0: string, 1: array<non-empty-string, string>} A search WHERE fragment (title/ref/store) and its params.
     */
    private function searchWhere(string $search): array
    {
        $search = trim($search);
        if ($search === '') {
            return ['', []];
        }

        return [
            ' WHERE ([[title]] LIKE :s OR [[source_ref]] LIKE :s OR [[store_name]] LIKE :s)',
            [':s' => '%' . $search . '%'],
        ];
    }
}
