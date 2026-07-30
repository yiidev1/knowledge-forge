<?php

declare(strict_types=1);

namespace App\Worker\Application;

/**
 * A unit of background work the worker knows how to drain — provisioning knowledge bases, processing
 * documents, cleaning up remote artifacts.
 *
 * Each drainer first recovers anything a previous crashed run left stranded, then claims and processes
 * up to a bounded number of items. Drainers are independent and ordered by the runner; one drainer's
 * failure does not stop the others.
 */
interface DrainerInterface
{
    /**
     * A short, stable name for logging (e.g. "kb-provisioning").
     */
    public function name(): string;

    /**
     * Returns stranded, timed-out items to a retryable state. Called before {@see drain()} on every run.
     */
    public function recover(): void;

    /**
     * Claims and processes up to $limit items.
     */
    public function drain(int $limit): DrainResult;
}
