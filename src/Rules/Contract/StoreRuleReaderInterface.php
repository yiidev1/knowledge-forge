<?php

declare(strict_types=1);

namespace App\Rules\Contract;

use App\Rules\Domain\StoreRuleItem;

/**
 * Read model for "which catalog rules does the rule architecture consider applicable to this store".
 *
 * A rule reaches a store one of two ways, and both are represented: it is scoped `common` (applies to every
 * store), or it carries a non-rejected `rule_store_links` row for that store's `source_id`. Rejected links are
 * excluded — an admin already decided the rule does not apply.
 *
 * Read-only and store-scoped by construction: every query takes the store's Order58 `source_id`, so one
 * store's page can never render another's rules.
 */
interface StoreRuleReaderInterface
{
    /**
     * @return list<StoreRuleItem> Active rules applicable to this store: common first, then store-specific,
     *                             each alphabetically by title.
     */
    public function findForStore(int $storeSourceId): array;
}
