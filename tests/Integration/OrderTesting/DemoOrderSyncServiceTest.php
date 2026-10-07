<?php

declare(strict_types=1);

namespace App\Tests\Integration\OrderTesting;

use App\OrderTesting\Application\Comparison\OrderNormalizer;
use App\OrderTesting\Application\DemoOrderDirectory;
use App\OrderTesting\Application\DemoOrderImporter;
use App\OrderTesting\Application\DemoOrderSyncService;
use App\OrderTesting\Infrastructure\DbDemoOrderRepository;
use App\OrderTesting\Infrastructure\DbTestAttemptRepository;
use App\Shared\Domain\Clock\SystemClock;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use Psr\Log\NullLogger;
use Yiisoft\Db\Connection\ConnectionInterface;

use function dirname;
use function fclose;
use function flock;
use function fopen;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotFalse;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

use const LOCK_EX;
use const LOCK_NB;
use const LOCK_UN;

/**
 * The lock that the console command and the "Sync demo orders" button share.
 *
 * ## What is really being guarded
 *
 * That a second caller is TOLD it cannot run, rather than blocking or running anyway. Two
 * administrators, or an administrator and somebody at a shell, will land in the same second
 * eventually, and the two failure modes are a web request that hangs until the server's timeout, or
 * two passes importing the same directory at once.
 */
final class DemoOrderSyncServiceTest extends Unit
{
    private const STORE = 1731;

    private ConnectionInterface $connection;
    private string $lockFile;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        $lock = tempnam(sys_get_temp_dir(), 'kf-ot-lock-');
        $this->lockFile = $lock === false ? sys_get_temp_dir() . '/kf-ot-lock' : $lock;
    }

    protected function _after(): void
    {
        $this->cleanup();
        @unlink($this->lockFile);
    }

    private function service(string $subdirectory = 'demo_mix_orders'): DemoOrderSyncService
    {
        $directory = new DemoOrderDirectory(dirname(__DIR__, 2) . '/_data/order-testing/' . $subdirectory);

        return new DemoOrderSyncService(
            $directory,
            new DemoOrderImporter(
                $directory,
                new DbDemoOrderRepository($this->connection, new SystemClock()),
                new DbTestAttemptRepository($this->connection),
                new OrderNormalizer(),
                new SystemClock(),
                new NullLogger(),
            ),
            $this->lockFile,
        );
    }

    public function testASyncRunsTheImporterAndReportsWhatItDid(): void
    {
        $outcome = $this->service()->sync();

        assertTrue($outcome->didRun());
        assertFalse($outcome->busy);
        assertNotNull($outcome->report);
        assertSame(4, $outcome->report->inserted);
        assertTrue($outcome->changedSomething());
    }

    /** The second sync of an unchanged directory must report "nothing changed", not "4 new". */
    public function testASecondSyncChangesNothing(): void
    {
        $this->service()->sync();
        $outcome = $this->service()->sync();

        assertTrue($outcome->didRun());
        assertNotNull($outcome->report);
        assertSame(0, $outcome->report->inserted);
        assertSame(0, $outcome->report->updated);
        assertSame(4, $outcome->report->unchanged);
        // What the page turns into "Demo orders are already up to date".
        assertFalse($outcome->changedSomething());
    }

    /**
     * A held lock refuses immediately rather than waiting.
     *
     * The lock is taken here by a separate handle on the same file, which is exactly what a running
     * console command looks like to the web request.
     */
    public function testAHeldLockIsReportedRatherThanWaitedOn(): void
    {
        $holder = fopen($this->lockFile, 'c');
        assertNotFalse($holder);
        assertTrue(flock($holder, LOCK_EX | LOCK_NB));

        $outcome = $this->service()->sync();

        assertTrue($outcome->busy);
        assertFalse($outcome->didRun());
        assertNull($outcome->report);

        flock($holder, LOCK_UN);
        fclose($holder);

        // And once released, the next caller gets through — the lock is not left poisoned.
        $after = $this->service()->sync();
        assertTrue($after->didRun());
    }

    /** The lock is released even when the directory was never usable. */
    public function testAnUnusableDirectoryIsReportedAndLeavesNoLockHeld(): void
    {
        $outcome = $this->service('no_such_directory')->sync();

        assertFalse($outcome->didRun());
        assertFalse($outcome->busy);
        assertNotNull($outcome->problem);

        $holder = fopen($this->lockFile, 'c');
        assertNotFalse($holder);
        assertTrue(flock($holder, LOCK_EX | LOCK_NB), 'the lock must not still be held');
        flock($holder, LOCK_UN);
        fclose($holder);
    }

    /** Scoped to this suite's own fixtures, never a blanket delete. */
    private function cleanup(): void
    {
        $this->connection->createCommand()
            ->delete('{{%order_testing_demo_orders}}', ['store_source_id' => self::STORE])->execute();
        $this->connection->createCommand()
            ->delete('{{%order_testing_attempts}}', ['store_source_id' => self::STORE])->execute();
    }
}
