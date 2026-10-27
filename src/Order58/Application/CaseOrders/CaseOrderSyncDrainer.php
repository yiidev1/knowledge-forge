<?php

declare(strict_types=1);

namespace App\Order58\Application\CaseOrders;

use App\Order58\Domain\CaseOrders\CaseOrderSyncRunRepositoryInterface;
use App\Order58\Domain\CaseOrders\CaseOrderSyncStatus;
use App\Shared\Domain\Clock\ClockInterface;
use App\Worker\Application\DrainerInterface;
use App\Worker\Application\DrainResult;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Executes the run a web request claimed, in the worker rather than in the request.
 *
 * ## Why the work is here and the decision is not
 *
 * {@see CaseOrderSyncCoordinator} decides and claims; this only executes what is already claimed. A
 * drainer that could decide would be a second place for the rules to live, and the worker runs
 * unattended — the one caller that must never be able to talk itself into a provider request.
 *
 * ## One run per drain, and never a retry
 *
 * At most one run can be active at a time (a unique index sees to that), so a pass handles one or
 * nothing. A run that fails is left failed: whether the provider was reached is often unknowable, and
 * an automatic retry against a provider that permits few requests is exactly the loop this
 * architecture exists to prevent. Recovery closes abandoned runs; it does not restart them.
 */
final readonly class CaseOrderSyncDrainer implements DrainerInterface
{
    public function __construct(
        private CaseOrderSyncService $sync,
        private CaseOrderSyncRunRepositoryInterface $runs,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {}

    public function name(): string
    {
        return 'order58-case-orders';
    }

    /** Closes claims whose holder stopped reporting. It never restarts them — see the class docblock. */
    public function recover(): void
    {
        $closed = $this->runs->recoverExpired($this->clock->now());

        if ($closed > 0) {
            $this->logger->warning('Case order synchronisation runs were closed after their lease expired.', [
                'reason' => 'order58_case_order_run_abandoned',
                'closed' => $closed,
            ]);
        }
    }

    public function drain(int $limit): DrainResult
    {
        if ($limit < 1) {
            return DrainResult::nothing();
        }

        $run = $this->runs->active();

        // Nothing claimed, or a claim this pass must not touch: FETCHED and PROCESSING mean another
        // pass is mid-flight through the same run.
        if ($run === null || $run->status !== CaseOrderSyncStatus::Claimed) {
            return DrainResult::nothing();
        }

        try {
            $outcome = $this->sync->execute($run);
        } catch (Throwable $e) {
            // The class name only: a driver or transport message can carry a credential or an order.
            $this->logger->error('Case order synchronisation failed in the worker.', [
                'reason' => 'order58_case_order_drain_failed',
                'run' => $run->id,
                'error_class' => $e::class,
            ]);

            return new DrainResult(0, 1);
        }

        return $outcome->isSuccess() ? new DrainResult(1, 0) : new DrainResult(0, 1);
    }
}
