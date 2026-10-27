<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\Order58\Web\CaseOrders\SyncAction;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function dirname;
use function file_get_contents;
use function str_contains;

/**
 * The press must finish the work, not merely ask for it.
 *
 * ## Why this is a source check rather than a behavioural one
 *
 * What went wrong was not a wrong answer — it was a dependency on something that does not run.
 * `SyncAction` claimed a run and left the fetch to the drainer inside `kf:worker:run`, which has no
 * timer and no cron entry on this host. Every unit and integration test still passed, because each
 * one drove the drainer itself. Nothing was asserting the thing a browser actually depends on: that
 * one HTTP request completes the synchronisation by itself.
 *
 * A behavioural test of that lives in `CaseOrderWorkflowTest` and runs the real service against a fake
 * provider. This one guards the wiring that test cannot see — that the action still performs the
 * execution step rather than delegating it back to a worker — so the regression fails here, by name,
 * instead of only in the browser.
 */
final class CaseOrderSyncExecutionBoundaryTest extends TestCase
{
    private function source(): string
    {
        $path = (new ReflectionClass(SyncAction::class))->getFileName();

        self::assertIsString($path);

        return (string) file_get_contents($path);
    }

    /** It executes the claim in the request, through the same service the worker uses. */
    public function testTheSyncActionExecutesTheRunItClaims(): void
    {
        $source = $this->source();

        self::assertTrue(
            str_contains($source, '$this->sync->execute('),
            'SyncAction must execute the run it claimed. Claiming alone depends on kf:worker:run, '
            . 'which is not scheduled on every host — and where it is not, the orders never arrive.',
        );
    }

    /** And it only ever executes a run of its own claiming. */
    public function testItExecutesOnlyWhatItClaimed(): void
    {
        $source = $this->source();

        self::assertTrue(
            str_contains($source, '$decision->startedWork()'),
            'The execution must be gated on this request having claimed the run; executing a joined '
            . 'run would ask the provider twice for one press.',
        );
    }

    /**
     * The action names no worker and no drainer.
     *
     * Not stylistic: a web request that reached into the worker's own classes would be a second place
     * the execution rules live, and the two would drift. Both paths go through the service.
     */
    public function testTheSyncActionDependsOnNoWorkerCode(): void
    {
        $source = $this->source();

        foreach (['CaseOrderSyncDrainer', 'DrainerInterface', 'WorkerRunner'] as $forbidden) {
            self::assertFalse(
                str_contains($source, $forbidden . '::'),
                'SyncAction must not depend on ' . $forbidden . '.',
            );
        }
    }

    /**
     * The redirect names the run, so the page it returns to describes that one and no other.
     *
     * Without the id the page had to infer which run the progress panel was about, and the only
     * available inference was "the most recent" — which is how a finished run's counts were shown as
     * the operation the operator was about to confirm.
     */
    public function testTheRedirectNamesTheRunItStarted(): void
    {
        self::assertTrue(
            str_contains($this->source(), '\'sync-run\' => (string) $decision->run->id'),
            'The POST must redirect back naming its own run, or the page falls back to guessing.',
        );
    }

    /** The drainer stays registered, so a host that does run the worker keeps the second path. */
    public function testTheDrainerIsStillWiredIntoTheWorker(): void
    {
        $config = (string) file_get_contents(dirname(__DIR__, 3) . '/config/common/di/worker.php');

        self::assertTrue(
            str_contains($config, 'CaseOrderSyncDrainer'),
            'Removing the drainer would leave an abandoned run with nothing able to finish it.',
        );
    }
}
