<?php

declare(strict_types=1);

namespace App\OrderTesting\Application;

use function fclose;
use function flock;
use function fopen;
use function is_resource;

use const LOCK_EX;
use const LOCK_NB;
use const LOCK_UN;

/**
 * Runs the demo-order import, once, under the one lock.
 *
 * ## Why the lock lives here and not in the command
 *
 * It used to live in `ImportDemoOrdersCommand`, which was fine while the command was the only caller.
 * It is not fine now that an administrator can start the same work from a page: two copies of the
 * flock dance would be two chances to disagree about which file is the lock, and the failure mode of
 * disagreeing is two administrators, or an administrator and somebody at a shell, importing the same
 * directory at the same time.
 *
 * So there is one service, one lock file, and two callers. The console command and the web action
 * both ask this; neither opens a file itself.
 *
 * ## Non-blocking on purpose
 *
 * A caller that cannot have the lock is told so immediately rather than waiting. For the command that
 * means a tick which overlaps a slow predecessor exits instead of scanning the same files twice; for
 * the page it means an administrator gets an answer rather than a request that hangs until the web
 * server's timeout.
 *
 * ## Nothing here is a background job
 *
 * The import is small local reads and, almost always, zero writes — a document whose content hash is
 * unchanged is not written at all. It runs inside the request. If that ever stops being true, this is
 * the one place that has to change, and the page is the only caller that would notice.
 */
final readonly class DemoOrderSyncService
{
    public function __construct(
        private DemoOrderDirectory $directory,
        private DemoOrderImporter $importer,
        /** Configuration, never request data. See config/common/di/order-testing.php. */
        private string $lockFile,
    ) {}

    /**
     * @param int|null $limit stop after this many files, so one pass stays bounded
     */
    public function sync(?int $limit = null): SyncOutcome
    {
        // Asked before the lock is taken: a missing directory is not a reason to make anybody else
        // wait, and it is the normal state of a machine that has never produced a demo order.
        $status = $this->directory->resolve();

        if (!$status->isUsable()) {
            return SyncOutcome::unusable((string) $status->problem);
        }

        $lock = @fopen($this->lockFile, 'c');

        if (!is_resource($lock)) {
            return SyncOutcome::unusable('The import lock file could not be opened: ' . $this->lockFile);
        }

        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return SyncOutcome::alreadyRunning();
        }

        try {
            return SyncOutcome::ran($this->importer->import($limit));
        } finally {
            // `finally`, not a catch: whatever goes wrong inside the importer, the lock is released.
            // Leaving it held would block every later run and every later click until the process
            // exited, and a web process can outlive one request.
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
