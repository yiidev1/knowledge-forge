<?php

declare(strict_types=1);

namespace App\Rules\Infrastructure;

use App\Rules\Contract\RuleCatalogRepositoryInterface;
use App\Shared\Infrastructure\Db\DbDateTime;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;

use function array_map;
use function array_values;
use function is_array;
use function is_numeric;

/**
 * MySQL-backed canonical rule catalog. All multi-statement work is orchestrated by
 * {@see \App\Rules\Application\RuleCatalogService} inside one transaction; the methods here are single writes/reads.
 */
final readonly class DbRuleCatalogRepository implements RuleCatalogRepositoryInterface
{
    private const RULES = '{{%rule_catalog_rules}}';
    private const SOURCES = '{{%rule_catalog_sources}}';

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function findIdByCanonicalHash(string $canonicalHash): ?int
    {
        $value = $this->connection
            ->createQuery()
            ->select('id')
            ->from(self::RULES)
            ->where(['canonical_hash' => $canonicalHash])
            ->scalar();

        return is_numeric($value) ? (int) $value : null;
    }

    public function insertCanonical(
        string $canonicalHash,
        string $descriptionHash,
        string $title,
        string $content,
        DateTimeImmutable $now,
    ): int {
        $ts = DbDateTime::format($now);

        $this->connection->createCommand()->insert(self::RULES, [
            'canonical_hash' => $canonicalHash,
            'description_hash' => $descriptionHash,
            'title' => $title,
            'content' => $content,
            // scope_type / classification_status fall back to their column defaults (unresolved / pending).
            'is_active' => 1,
            'created_at' => $ts,
            'updated_at' => $ts,
        ])->execute();

        return (int) $this->connection->getLastInsertId();
    }

    public function findSourceLink(int $order58RuleRecordId): ?array
    {
        $row = $this->connection
            ->createQuery()
            ->select(['rule_catalog_rule_id', 'relation_type'])
            ->from(self::SOURCES)
            ->where(['order58_rule_record_id' => $order58RuleRecordId])
            ->limit(1)
            ->one();

        if (!is_array($row)) {
            return null;
        }

        return [
            'canonical_id' => (int) $row['rule_catalog_rule_id'],
            'relation_type' => (string) $row['relation_type'],
        ];
    }

    public function countSourcesForCanonical(int $canonicalId): int
    {
        return (int) $this->connection
            ->createQuery()
            ->from(self::SOURCES)
            ->where(['rule_catalog_rule_id' => $canonicalId])
            ->count();
    }

    public function insertSourceLink(
        int $canonicalId,
        int $order58RuleRecordId,
        string $relationType,
        DateTimeImmutable $now,
    ): void {
        $this->connection->createCommand()->insert(self::SOURCES, [
            'rule_catalog_rule_id' => $canonicalId,
            'order58_rule_record_id' => $order58RuleRecordId,
            'relation_type' => $relationType,
            'created_at' => DbDateTime::format($now),
        ])->execute();
    }

    public function relinkSource(
        int $order58RuleRecordId,
        int $newCanonicalId,
        string $relationType,
        DateTimeImmutable $now,
    ): void {
        $this->connection->createCommand()->update(
            self::SOURCES,
            ['rule_catalog_rule_id' => $newCanonicalId, 'relation_type' => $relationType],
            ['order58_rule_record_id' => $order58RuleRecordId],
        )->execute();
    }

    public function recomputeActive(int $canonicalId, DateTimeImmutable $now): void
    {
        // A canonical rule is active when at least one of its linked source records is still active.
        $sql = 'UPDATE {{%rule_catalog_rules}} [[c]] SET [[c]].[[is_active]] = CASE WHEN EXISTS('
            . 'SELECT 1 FROM {{%rule_catalog_sources}} [[s]]'
            . ' JOIN {{%order58_rule_records}} [[r]] ON [[r]].[[id]] = [[s]].[[order58_rule_record_id]]'
            . ' WHERE [[s]].[[rule_catalog_rule_id]] = [[c]].[[id]] AND [[r]].[[is_active]] = 1'
            . ') THEN 1 ELSE 0 END, [[c]].[[updated_at]] = :ts WHERE [[c]].[[id]] = :id';

        $this->connection->createCommand($sql, [':ts' => DbDateTime::format($now), ':id' => $canonicalId])->execute();
    }

    public function findCanonicalIdForRecord(int $order58RuleRecordId): ?int
    {
        $value = $this->connection
            ->createQuery()
            ->select('rule_catalog_rule_id')
            ->from(self::SOURCES)
            ->where(['order58_rule_record_id' => $order58RuleRecordId])
            ->scalar();

        return is_numeric($value) ? (int) $value : null;
    }

    public function findClassification(int $canonicalId): ?array
    {
        $row = $this->connection
            ->createQuery()
            ->select(['title', 'content', 'canonical_hash', 'scope_type', 'classification_status', 'is_active', 'is_globally_available'])
            ->from(self::RULES)
            ->where(['id' => $canonicalId])
            ->limit(1)
            ->one();

        if (!is_array($row)) {
            return null;
        }

        return [
            'title' => (string) $row['title'],
            'content' => (string) $row['content'],
            'canonical_hash' => (string) $row['canonical_hash'],
            'scope_type' => (string) $row['scope_type'],
            'classification_status' => (string) $row['classification_status'],
            'is_active' => (bool) (int) $row['is_active'],
            'is_globally_available' => (bool) (int) $row['is_globally_available'],
        ];
    }

    public function updateClassification(
        int $canonicalId,
        string $scopeType,
        string $classificationStatus,
        ?string $reason,
        ?string $detectedStoreText,
        ?int $reviewedByAdminId,
        DateTimeImmutable $now,
    ): void {
        $ts = DbDateTime::format($now);
        $values = [
            'scope_type' => $scopeType,
            'classification_status' => $classificationStatus,
            'classification_reason' => $reason,
            'detected_store_text' => $detectedStoreText,
            'updated_at' => $ts,
        ];
        if ($reviewedByAdminId !== null) {
            $values['reviewed_by_admin_id'] = $reviewedByAdminId;
            $values['reviewed_at'] = $ts;
        }

        $this->connection->createCommand()->update(self::RULES, $values, ['id' => $canonicalId])->execute();
    }

    public function setGloballyAvailable(int $canonicalId, bool $available, DateTimeImmutable $now): void
    {
        $this->connection->createCommand()->update(
            self::RULES,
            ['is_globally_available' => $available ? 1 : 0, 'updated_at' => DbDateTime::format($now)],
            ['id' => $canonicalId],
        )->execute();
    }

    public function findSourceStoreIdForCanonical(int $canonicalId): ?int
    {
        $value = $this->connection
            ->createQuery()
            ->select('r.source_store_id')
            ->from(['s' => self::SOURCES])
            ->innerJoin(['r' => '{{%order58_rule_records}}'], 'r.id = s.order58_rule_record_id')
            ->where(['s.rule_catalog_rule_id' => $canonicalId, 'r.is_active' => 1])
            ->andWhere(['not', ['r.source_store_id' => null]])
            ->limit(1)
            ->scalar();

        return is_numeric($value) ? (int) $value : null;
    }

    public function listActiveCanonicalIds(): array
    {
        $rows = $this->connection
            ->createQuery()
            ->select('id')
            ->from(self::RULES)
            ->where(['is_active' => 1])
            ->column();

        return array_values(array_map(static fn($v): int => (int) $v, $rows));
    }
}
