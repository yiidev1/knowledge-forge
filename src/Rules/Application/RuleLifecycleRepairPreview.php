<?php

declare(strict_types=1);

namespace App\Rules\Application;

use App\Document\Domain\DocumentSourceType;
use App\Document\Domain\DocumentStatus;
use Yiisoft\Db\Connection\ConnectionInterface;

use function is_numeric;
use function max;

/**
 * Read-only preview of what the next successful full Rules sync (+ automatic post-sync projection reconcile)
 * would heal for stale inactive / unmaterialized rules. Never writes. Prefer the normal Sync Rules path for
 * the permanent repair — this report exists so operators can inspect candidates safely before syncing.
 */
final readonly class RuleLifecycleRepairPreview
{
    public function __construct(
        private ConnectionInterface $connection,
        private EnsureCommonRulesKnowledgeBaseService $globalBase,
    ) {}

    /**
     * @return array{
     *     synced_source_rules: int,
     *     active_sources: int,
     *     inactive_sources: int,
     *     stale_inactive_candidates: int,
     *     would_reactivate_if_still_upstream: int,
     *     canonical_inactive_with_inactive_sources: int,
     *     active_canonical_rules: int,
     *     global_documents_live: int,
     *     global_documents_missing_for_active: int,
     *     hidden_rules_kb_present: bool,
     * }
     */
    public function report(): array
    {
        $synced = (int) $this->connection->createQuery()->from('{{%order58_rule_records}}')->count();
        $activeSources = (int) $this->connection
            ->createQuery()
            ->from('{{%order58_rule_records}}')
            ->where(['is_active' => 1])
            ->count();
        $inactiveSources = $synced - $activeSources;

        // Previously mirrored (has a sync hash) but currently inactive — if Order58 still returns them on the
        // next successful full scan, markSeen will reactivate. Correctly swept missing rules stay inactive
        // because they will not appear in that scan.
        $staleCandidates = (int) $this->connection
            ->createQuery()
            ->from('{{%order58_rule_records}}')
            ->where(['and', ['is_active' => 0], ['is not', 'sync_hash', null]])
            ->count();

        $canonicalInactive = (int) $this->connection
            ->createQuery()
            ->from('{{%rule_catalog_rules}}')
            ->where(['is_active' => 0])
            ->count();

        $activeCanonical = (int) $this->connection
            ->createQuery()
            ->from('{{%rule_catalog_rules}}')
            ->where(['is_active' => 1])
            ->count();

        $base = $this->globalBase->find();
        $liveGlobal = 0;
        $missingGlobal = $activeCanonical;
        if ($base !== null) {
            $liveGlobal = (int) $this->connection
                ->createQuery()
                ->from('{{%documents}}')
                ->where([
                    'knowledge_base_id' => $base->id(),
                    'source_type' => DocumentSourceType::Order58RuleGlobal->value,
                    'is_enabled' => 1,
                ])
                ->andWhere(['<>', 'status', DocumentStatus::Deleted->value])
                ->count();

            $materialized = $this->connection->createCommand(
                'SELECT COUNT(*) FROM {{%rule_catalog_rules}} [[c]]'
                . ' WHERE [[c]].[[is_active]] = 1'
                . ' AND EXISTS ('
                . '   SELECT 1 FROM {{%documents}} [[d]]'
                . '   WHERE [[d]].[[knowledge_base_id]] = :kb'
                . '     AND [[d]].[[source_type]] = :type'
                . '     AND [[d]].[[source_ref]] = CAST([[c]].[[id]] AS CHAR)'
                . '     AND [[d]].[[is_enabled]] = 1'
                . '     AND [[d]].[[status]] <> :deleted'
                . ' )',
                [
                    ':kb' => $base->id(),
                    ':type' => DocumentSourceType::Order58RuleGlobal->value,
                    ':deleted' => DocumentStatus::Deleted->value,
                ],
            )->queryScalar();
            $materialized = is_numeric($materialized) ? (int) $materialized : 0;
            $missingGlobal = max(0, $activeCanonical - $materialized);
        }

        return [
            'synced_source_rules' => $synced,
            'active_sources' => $activeSources,
            'inactive_sources' => $inactiveSources,
            'stale_inactive_candidates' => $staleCandidates,
            'would_reactivate_if_still_upstream' => $staleCandidates,
            'canonical_inactive_with_inactive_sources' => $canonicalInactive,
            'active_canonical_rules' => $activeCanonical,
            'global_documents_live' => $liveGlobal,
            'global_documents_missing_for_active' => $missingGlobal,
            'hidden_rules_kb_present' => $base !== null,
        ];
    }
}
