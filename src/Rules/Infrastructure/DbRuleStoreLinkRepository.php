<?php

declare(strict_types=1);

namespace App\Rules\Infrastructure;

use App\Rules\Contract\RuleStoreLinkRepositoryInterface;
use App\Rules\Domain\StoreMatchMethod;
use App\Rules\Domain\StoreMatchStatus;
use App\Shared\Infrastructure\Db\DbDateTime;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;

use function array_map;
use function array_values;

/**
 * MySQL-backed rule→store links. The system upsert protects admin decisions: on a duplicate key it only
 * overwrites a row whose `created_by_type` is still 'system' (never an admin-confirmed or -rejected link).
 */
final readonly class DbRuleStoreLinkRepository implements RuleStoreLinkRepositoryInterface
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function upsertSystemLink(
        int $canonicalId,
        int $storeSourceId,
        StoreMatchStatus $status,
        StoreMatchMethod $method,
        ?string $matchedText,
        ?float $confidence,
        DateTimeImmutable $now,
    ): void {
        $ts = DbDateTime::format($now);

        $sql = 'INSERT INTO {{%rule_store_links}}'
            . ' ([[rule_catalog_rule_id]], [[store_source_id]], [[match_status]], [[match_method]], [[matched_text]],'
            . ' [[confidence]], [[is_primary]], [[created_by_type]], [[created_at]], [[updated_at]])'
            . ' VALUES (:rule, :store, :status, :method, :text, :conf, 0, \'system\', :ts, :ts)'
            . ' ON DUPLICATE KEY UPDATE'
            . ' [[match_status]] = IF([[created_by_type]] <=> \'system\', VALUES([[match_status]]), [[match_status]]),'
            . ' [[match_method]] = IF([[created_by_type]] <=> \'system\', VALUES([[match_method]]), [[match_method]]),'
            . ' [[matched_text]] = IF([[created_by_type]] <=> \'system\', VALUES([[matched_text]]), [[matched_text]]),'
            . ' [[confidence]] = IF([[created_by_type]] <=> \'system\', VALUES([[confidence]]), [[confidence]]),'
            . ' [[updated_at]] = IF([[created_by_type]] <=> \'system\', VALUES([[updated_at]]), [[updated_at]])';

        $this->connection->createCommand($sql, [
            ':rule' => $canonicalId,
            ':store' => $storeSourceId,
            ':status' => $status->value,
            ':method' => $method->value,
            ':text' => $matchedText,
            ':conf' => $confidence,
            ':ts' => $ts,
        ])->execute();
    }

    public function setAdminLink(int $canonicalId, int $storeSourceId, StoreMatchStatus $status, int $adminId, DateTimeImmutable $now): void
    {
        $ts = DbDateTime::format($now);

        $sql = 'INSERT INTO {{%rule_store_links}}'
            . ' ([[rule_catalog_rule_id]], [[store_source_id]], [[match_status]], [[match_method]], [[is_primary]],'
            . ' [[created_by_type]], [[created_by_id]], [[created_at]], [[updated_at]])'
            . ' VALUES (:rule, :store, :status, \'manual\', 0, \'admin\', :admin, :ts, :ts)'
            . ' ON DUPLICATE KEY UPDATE [[match_status]] = VALUES([[match_status]]), [[match_method]] = \'manual\','
            . ' [[created_by_type]] = \'admin\', [[created_by_id]] = VALUES([[created_by_id]]), [[updated_at]] = VALUES([[updated_at]])';

        $this->connection->createCommand($sql, [
            ':rule' => $canonicalId,
            ':store' => $storeSourceId,
            ':status' => $status->value,
            ':admin' => $adminId,
            ':ts' => $ts,
        ])->execute();
    }

    public function findConfirmedStoreIds(int $canonicalId): array
    {
        $rows = $this->connection
            ->createQuery()
            ->select('store_source_id')
            ->from('{{%rule_store_links}}')
            ->where(['rule_catalog_rule_id' => $canonicalId, 'match_status' => StoreMatchStatus::Confirmed->value])
            ->column();

        return array_values(array_map(static fn($v): int => (int) $v, $rows));
    }

    public function findLinkedStoreIds(int $canonicalId): array
    {
        $rows = $this->connection
            ->createQuery()
            ->select('store_source_id')
            ->from('{{%rule_store_links}}')
            ->where(['rule_catalog_rule_id' => $canonicalId])
            ->column();

        return array_values(array_map(static fn($v): int => (int) $v, $rows));
    }
}
