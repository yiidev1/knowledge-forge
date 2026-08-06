<?php

declare(strict_types=1);

namespace App\Rules\Contract;

use App\Rules\Domain\StoreMatchMethod;
use App\Rules\Domain\StoreMatchStatus;
use DateTimeImmutable;

/**
 * Persistence for rule→store links. Idempotent by the UNIQUE (rule_catalog_rule_id, store_source_id).
 */
interface RuleStoreLinkRepositoryInterface
{
    /**
     * Upserts a link produced by the automated matcher (created_by_type = 'system'). An existing link created
     * or changed by an admin (created_by_type != 'system' — e.g. a confirmed or rejected review decision) is
     * left untouched, so a resync never overwrites a human decision.
     */
    public function upsertSystemLink(
        int $canonicalId,
        int $storeSourceId,
        StoreMatchStatus $status,
        StoreMatchMethod $method,
        ?string $matchedText,
        ?float $confidence,
        DateTimeImmutable $now,
    ): void;

    /**
     * Upserts an admin review decision on a link (created_by_type = 'admin'), which a later automated sync must
     * never overwrite. Used to confirm or reject a store match.
     */
    public function setAdminLink(
        int $canonicalId,
        int $storeSourceId,
        StoreMatchStatus $status,
        int $adminId,
        DateTimeImmutable $now,
    ): void;

    /**
     * @return list<int> Store source ids with a confirmed link for this canonical rule.
     */
    public function findConfirmedStoreIds(int $canonicalId): array;

    /**
     * @return list<int> Every store source id ever linked to this canonical rule (any status), so a
     *                   projection can be retired from a store whose link was rejected or removed.
     */
    public function findLinkedStoreIds(int $canonicalId): array;
}
