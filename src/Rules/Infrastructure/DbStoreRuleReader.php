<?php

declare(strict_types=1);

namespace App\Rules\Infrastructure;

use App\Rules\Contract\StoreRuleReaderInterface;
use App\Rules\Domain\ClassificationStatus;
use App\Rules\Domain\RuleScope;
use App\Rules\Domain\StoreMatchStatus;
use App\Rules\Domain\StoreRuleItem;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * MySQL read model for the catalog rules applicable to one store.
 *
 * One query, no N+1: the canonical catalog is LEFT JOINed to this store's link row so a common rule (which has
 * no per-store link) and a store-specific rule (which does) come back in the same set. Rejected links are
 * filtered out, as are inactive rules — a deactivated upstream rule no longer applies to anything.
 *
 * Store scoping is structural: `:storeSourceId` is the only way into the link table, so a row belonging to
 * another store cannot be selected.
 */
final readonly class DbStoreRuleReader implements StoreRuleReaderInterface
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function findForStore(int $storeSourceId): array
    {
        $sql = <<<SQL
            SELECT r.id                AS canonical_id,
                   r.title             AS title,
                   r.content           AS content,
                   r.scope_type        AS scope_type,
                   r.classification_status AS classification_status,
                   r.is_active         AS is_active,
                   l.match_status      AS match_status,
                   r.updated_at        AS updated_at
              FROM {{%rule_catalog_rules}} r
              LEFT JOIN {{%rule_store_links}} l
                     ON l.rule_catalog_rule_id = r.id
                    AND l.store_source_id = :storeSourceId
             WHERE r.is_active = 1
               AND (
                     r.scope_type = :common
                     OR (l.id IS NOT NULL AND l.match_status <> :rejected)
                   )
             ORDER BY (r.scope_type = :common) DESC, r.title ASC, r.id ASC
            SQL;

        $rows = $this->connection
            ->createCommand($sql, [
                ':storeSourceId' => $storeSourceId,
                ':common' => RuleScope::Common->value,
                ':rejected' => StoreMatchStatus::Rejected->value,
            ])
            ->queryAll();

        $items = [];
        foreach ($rows as $row) {
            $matchStatus = $row['match_status'] === null
                ? null
                : StoreMatchStatus::tryFrom((string) $row['match_status']);

            $items[] = new StoreRuleItem(
                canonicalId: (int) $row['canonical_id'],
                title: (string) $row['title'],
                scope: RuleScope::tryFrom((string) $row['scope_type']) ?? RuleScope::Unresolved,
                classificationLabel: $this->classificationLabel($row['classification_status']),
                isActive: (bool) (int) $row['is_active'],
                matchStatus: $matchStatus,
                updatedAt: (string) $row['updated_at'],
                content: $row['content'] === null ? null : (string) $row['content'],
            );
        }

        return $items;
    }

    private function classificationLabel(mixed $raw): string
    {
        $status = ClassificationStatus::tryFrom((string) $raw);

        return $status?->label() ?? 'Unclassified';
    }
}
