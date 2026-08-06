<?php

declare(strict_types=1);

namespace App\Rules\Contract;

use App\Rules\Domain\AliasType;
use App\Rules\Domain\ApprovedAlias;
use DateTimeImmutable;

/**
 * Persistence for store aliases used by the matcher. Idempotent by the UNIQUE (store_source_id, normalized_alias).
 */
interface StoreAliasRepositoryInterface
{
    public function upsertApproved(
        int $storeSourceId,
        string $alias,
        string $normalizedAlias,
        AliasType $type,
        ?int $createdByAdminId,
        DateTimeImmutable $now,
    ): void;

    /**
     * @return list<ApprovedAlias> Every approved alias across all stores.
     */
    public function findApprovedAliases(): array;
}
