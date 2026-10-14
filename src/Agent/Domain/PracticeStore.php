<?php

declare(strict_types=1);

namespace App\Agent\Domain;

use function trim;

/**
 * A store an agent may practise on — the projection the practice picker needs.
 *
 * Keyed on the **mirrored store's own `source_id`**, not on a knowledge base: this surface is about
 * recordings and mirrored orders, which hang off the store, and a store with plenty of both may have no
 * knowledge base at all. That is the whole difference from {@see AgentStore}, and it is why the two are
 * separate types rather than one with a nullable field.
 *
 * Every agent sees the same list. There is no per-agent assignment and `account_id` plays no part in
 * building it.
 */
final readonly class PracticeStore
{
    public function __construct(
        public int $sourceId,
        public string $name,
        public ?string $company = null,
        /** How many of this store's recordings an agent may actually practise on. */
        public int $recordingCount = 0,
    ) {}

    /**
     * The company line, or null when the mirror does not carry one.
     *
     * No city or state: `order58_stores` holds those only inside `snapshot_json`, and digging them out
     * of JSON for a picker card would be a parse per row for a line the card can do without.
     */
    public function subtitle(): ?string
    {
        $company = $this->company === null ? '' : trim($this->company);

        return $company === '' ? null : $company;
    }
}
