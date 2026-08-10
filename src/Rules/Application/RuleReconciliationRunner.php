<?php

declare(strict_types=1);

namespace App\Rules\Application;

use App\Rules\Contract\RuleCatalogRepositoryInterface;
use App\Shared\Domain\Clock\ClockInterface;
use DateTimeImmutable;

use function count;

/**
 * Reconciles the searchable projections of every active canonical rule from their current classification +
 * global-availability flag — the backfill/repair path that brings existing rules up to the "every active rule is
 * globally available" model without a re-sync.
 *
 * Purely local and idempotent: it only creates/updates/retires generated documents through
 * {@see RuleProjectionReconciler} (which queues work for the worker) and NEVER calls OpenAI. Running it twice
 * over unchanged data creates no duplicate documents.
 */
final readonly class RuleReconciliationRunner
{
    public function __construct(
        private RuleCatalogRepositoryInterface $catalog,
        private RuleProjectionReconciler $reconciler,
        private ClockInterface $clock,
    ) {}

    /**
     * Reconciles all active canonical rules. Returns the number of rules processed.
     * Uses `$now` when provided (e.g. during a rules sync); otherwise the shared clock.
     */
    public function reconcileAllActive(?DateTimeImmutable $now = null): int
    {
        $at = $now ?? $this->clock->now();
        $ids = $this->catalog->listActiveCanonicalIds();
        foreach ($ids as $canonicalId) {
            $this->reconciler->reconcile($canonicalId, $at);
        }

        return count($ids);
    }
}
