<?php

declare(strict_types=1);

namespace App\Rules\Infrastructure;

use App\Rules\Contract\StoreAliasRepositoryInterface;
use App\Rules\Domain\AliasType;
use App\Rules\Domain\ApprovedAlias;
use App\Shared\Infrastructure\Db\DbDateTime;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;

use function is_array;

/**
 * MySQL-backed store aliases. Seeding is idempotent via the UNIQUE (store_source_id, normalized_alias).
 */
final readonly class DbStoreAliasRepository implements StoreAliasRepositoryInterface
{
    private const TABLE = '{{%order58_store_aliases}}';

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function upsertApproved(
        int $storeSourceId,
        string $alias,
        string $normalizedAlias,
        AliasType $type,
        ?int $createdByAdminId,
        DateTimeImmutable $now,
    ): void {
        $ts = DbDateTime::format($now);
        $insert = [
            'store_source_id' => $storeSourceId,
            'alias' => $alias,
            'normalized_alias' => $normalizedAlias,
            'alias_type' => $type->value,
            'is_approved' => 1,
            'created_by_admin_id' => $createdByAdminId,
            'created_at' => $ts,
            'updated_at' => $ts,
        ];
        // On a repeat seed, refresh only the display alias/type/approval — never the identity columns.
        $update = ['alias' => $alias, 'alias_type' => $type->value, 'is_approved' => 1, 'updated_at' => $ts];

        $this->connection->createCommand()->upsert(self::TABLE, $insert, $update)->execute();
    }

    public function findApprovedAliases(): array
    {
        $rows = $this->connection
            ->createQuery()
            ->select(['store_source_id', 'alias', 'normalized_alias', 'alias_type'])
            ->from(self::TABLE)
            ->where(['is_approved' => 1])
            ->all();

        $aliases = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $aliases[] = new ApprovedAlias(
                    (int) $row['store_source_id'],
                    (string) $row['alias'],
                    (string) $row['normalized_alias'],
                    AliasType::from((string) $row['alias_type']),
                );
            }
        }

        return $aliases;
    }
}
