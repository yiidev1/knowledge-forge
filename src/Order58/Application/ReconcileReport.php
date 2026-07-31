<?php

declare(strict_types=1);

namespace App\Order58\Application;

/**
 * The outcome of an active-status reconciliation pass: how many mirror rows were checked, how many store
 * and knowledge-base rows were actually corrected, and how many were skipped because their snapshot held no
 * usable active flag. Zero corrections means the mirror was already consistent.
 */
final readonly class ReconcileReport
{
    public function __construct(
        public int $storesChecked,
        public int $storesCorrected,
        public int $knowledgeBasesCorrected,
        public int $skippedInvalid,
    ) {}
}
