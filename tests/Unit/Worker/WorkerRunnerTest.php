<?php

declare(strict_types=1);

namespace App\Tests\Unit\Worker;

use App\Tests\Support\Fake\Worker\FakeWorkerLock;
use App\Tests\Support\Fake\Worker\StubDrainer;
use App\Worker\Application\DrainResult;
use App\Worker\Application\WorkerRunner;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final class WorkerRunnerTest extends Unit
{
    public function testAggregatesDrainerResultsAndReleasesTheLock(): void
    {
        $lock = new FakeWorkerLock();
        $a = new StubDrainer('a', new DrainResult(2, 0));
        $b = new StubDrainer('b', new DrainResult(1, 1));

        $result = (new WorkerRunner($lock, [$a, $b]))->run(5);

        assertTrue($result->lockAcquired);
        assertSame(3, $result->processed);
        assertSame(1, $result->failed);
        assertSame(1, $result->exitCode()); // a failure occurred
        assertTrue($a->recovered);
        assertTrue($a->drained);
        assertTrue($b->drained);
        assertTrue($lock->released);
    }

    public function testDoesNothingWhenTheLockIsHeld(): void
    {
        $lock = new FakeWorkerLock(acquirable: false);
        $drainer = new StubDrainer('a', new DrainResult(9, 9));

        $result = (new WorkerRunner($lock, [$drainer]))->run(5);

        assertFalse($result->lockAcquired);
        assertSame(0, $result->exitCode()); // a held lock is a normal no-op
        assertFalse($drainer->drained);
    }

    public function testCleanRunExitsZero(): void
    {
        $result = (new WorkerRunner(new FakeWorkerLock(), [new StubDrainer('a', new DrainResult(4, 0))]))->run(5);

        assertSame(0, $result->exitCode());
    }

    public function testDrainerThrowingIsAnInfrastructureFailureButOthersStillRun(): void
    {
        $lock = new FakeWorkerLock();
        $bad = new StubDrainer('bad', DrainResult::nothing(), throwOnDrain: true);
        $good = new StubDrainer('good', new DrainResult(1, 0));

        $result = (new WorkerRunner($lock, [$bad, $good]))->run(5);

        assertTrue($result->infraFailure);
        assertSame(70, $result->exitCode());
        assertTrue($good->drained); // one drainer's fault does not stop the rest
        assertTrue($lock->released); // lock released even after a throw
    }
}
