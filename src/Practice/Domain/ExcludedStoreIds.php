<?php

declare(strict_types=1);

namespace App\Practice\Domain;

use function array_keys;
use function in_array;
use function preg_match;
use function preg_split;
use function sort;

/**
 * The stores a topic may not be newly saved against: Order58's test and demo accounts, by store id.
 *
 * ## Why a list and not a rule
 *
 * Nothing in Order58's data marks a store as a test or demo account — not a column of the mirror, not a
 * field of the provider's store snapshot. The accounts can be recognised by a person (a name like
 * "0000000004 Test", a description reading "for BI KITCHEN", a `DEMO…` company code), but turning any of
 * those into a rule would sooner or later hide a real store whose name happens to contain the word. So
 * the accounts are named explicitly, in `PRACTICE_TOPIC_EXCLUDED_STORE_IDS`, and adding or removing one
 * is a configuration change rather than a code change.
 *
 * ## Lenient on purpose
 *
 * The setting is read as a string and every token that is not a positive whole number is ignored. A typo
 * then fails to exclude one store — which shows up in the dropdown and is fixed in `.env` — rather than
 * stopping the application.
 */
final readonly class ExcludedStoreIds
{
    /** @param list<int> $ids */
    public function __construct(public array $ids = []) {}

    /** `"61, 71,abc,,0"` → `[61, 71]`. Duplicates collapse; the order is ascending. */
    public static function fromSetting(string $setting): self
    {
        $seen = [];

        foreach (preg_split('/[\s,]+/', $setting) ?: [] as $token) {
            if (preg_match('/^[1-9][0-9]{0,17}$/', $token) === 1) {
                $seen[(int) $token] = true;
            }
        }

        $ids = array_keys($seen);
        sort($ids);

        return new self($ids);
    }

    public function contains(int $storeSourceId): bool
    {
        return in_array($storeSourceId, $this->ids, true);
    }

    public function isEmpty(): bool
    {
        return $this->ids === [];
    }
}
