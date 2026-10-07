<?php

declare(strict_types=1);

namespace App\OrderTesting\Web\Sync;

use App\OrderTesting\Application\DemoOrderSyncService;
use App\OrderTesting\Application\ImportReport;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function implode;
use function sprintf;

/**
 * "Sync demo orders" on the Order Testing picker: run the import now, from the page.
 *
 * ## It calls the service, it does not run the command
 *
 * The work is {@see DemoOrderSyncService} — the same object `kf:order-testing:import-demo-orders`
 * calls, under the same lock file. Shelling out to `yii` would have been a second way to start the
 * same work, with its own PHP process, its own configuration load and its own failure modes, and a
 * web request that can execute a shell command is a liability regardless of how carefully the
 * arguments are built. There are no arguments here to build: this endpoint takes no input at all
 * beyond the CSRF token.
 *
 * ## This button IS the synchronisation
 *
 * Demo-order import is manual by decision, not by omission: nothing runs it on a timer, and nothing is
 * meant to. The flow is that an administrator finishes a test in Order58, comes back here and presses
 * this. Without it, importing would mean shell access, which an administrator testing the training
 * workflow does not have and should not need.
 *
 * The console command remains for server-side and debugging use and calls the same service.
 *
 * ## The lock is the honest answer to a double click
 *
 * The second request finds the lock held and says so, rather than queueing behind the first or
 * silently importing twice. The button also disables itself on submit, but that is a courtesy — the
 * lock is what makes it correct, because it also holds across two browser tabs, across two
 * administrators, and against somebody running the command at a shell.
 */
final readonly class Action
{
    public function __construct(
        private DemoOrderSyncService $sync,
        private FlashMessages $flash,
        private Redirect $redirect,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(): ResponseInterface
    {
        try {
            $outcome = $this->sync->sync();
        } catch (Throwable $e) {
            // The importer already swallows one bad file; reaching here means something larger. The
            // operator gets a plain sentence and the detail goes to the log, because this message is
            // rendered on a page.
            $this->logger->error('Order Testing: manual demo order sync failed', ['error' => $e->getMessage()]);
            $this->flash->error('Demo order sync failed. The details are in the application log.');

            return $this->back();
        }

        if ($outcome->busy) {
            // Not an error: another administrator — or somebody at a shell — has it, and the right
            // thing is to wait a moment rather than force a second pass over the same directory.
            $this->flash->warning('Demo order sync is already running.');

            return $this->back();
        }

        $report = $outcome->report;

        if ($report === null) {
            $this->flash->error('Demo orders cannot be synced: ' . (string) $outcome->problem);

            return $this->back();
        }

        if (!$outcome->changedSomething()) {
            $this->flash->info('Demo orders are already up to date.' . $this->refusalNote($report));

            return $this->back();
        }

        $this->flash->success($this->summary($report) . $this->refusalNote($report));

        return $this->back();
    }

    /**
     * "Demo orders synced — 1 new, 9 unchanged".
     *
     * `updated` is named only when there is one. A document that changed on disk is a different event
     * from a new demo order, and reporting "0 updated" on every sync would train an operator to stop
     * reading the sentence.
     */
    private function summary(ImportReport $report): string
    {
        $parts = [sprintf('%d new', $report->inserted)];

        if ($report->updated > 0) {
            $parts[] = sprintf('%d updated', $report->updated);
        }

        $parts[] = sprintf('%d unchanged', $report->unchanged);

        return 'Demo orders synced — ' . implode(', ', $parts) . '.';
    }

    /**
     * Refused files are never left unsaid.
     *
     * A sync that reports success while quietly skipping a malformed document is how a trainee's
     * order goes missing with nobody looking for it. The count goes in the flash; the filename and
     * the gate are already in the log.
     */
    private function refusalNote(ImportReport $report): string
    {
        $skipped = $report->refused + $report->unreadable;

        return $skipped === 0
            ? ''
            : sprintf(' %d file(s) could not be imported — see the application log.', $skipped);
    }

    private function back(): ResponseInterface
    {
        // Post/Redirect/Get, so a refresh cannot replay the sync. Straight back to the picker, which
        // is where the button is.
        return $this->redirect->afterPost('order-testing');
    }
}
