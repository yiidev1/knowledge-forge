<?php

declare(strict_types=1);

namespace App\OrderTesting\Web\Sync;

use App\OrderTesting\Application\DemoOrderSyncService;
use App\OrderTesting\Application\ImportReport;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\FormData;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function implode;
use function preg_match;
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

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        // Worked out before anything runs, so every outcome below — including a thrown one — returns
        // the operator to the page they pressed the button on.
        $back = $this->target($request);

        try {
            $outcome = $this->sync->sync();
        } catch (Throwable $e) {
            // The importer already swallows one bad file; reaching here means something larger. The
            // operator gets a plain sentence and the detail goes to the log, because this message is
            // rendered on a page.
            $this->logger->error('Order Testing: manual demo order sync failed', ['error' => $e->getMessage()]);
            $this->flash->error('Demo order sync failed. The details are in the application log.');

            return $this->back($back);
        }

        if ($outcome->busy) {
            // Not an error: another administrator — or somebody at a shell — has it, and the right
            // thing is to wait a moment rather than force a second pass over the same directory.
            $this->flash->warning('Demo order sync is already running.');

            return $this->back($back);
        }

        $report = $outcome->report;

        if ($report === null) {
            $this->flash->error('Demo orders cannot be synced: ' . (string) $outcome->problem);

            return $this->back($back);
        }

        if (!$outcome->changedSomething()) {
            $this->flash->info('Demo orders are already up to date.' . $this->refusalNote($report));

            return $this->back($back);
        }

        $this->flash->success($this->summary($report) . $this->refusalNote($report));

        return $this->back($back);
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

    /**
     * Post/Redirect/Get, so a refresh cannot replay the sync — back to wherever it was pressed.
     *
     * @param array{string, array<string, scalar|null>} $target
     */
    private function back(array $target): ResponseInterface
    {
        [$route, $arguments] = $target;

        return $this->redirect->afterPost($route, $arguments);
    }

    /**
     * Which page to return to, and with what.
     *
     * ## Why this is an allow-list and not the submitted URL
     *
     * The alternative — posting a `redirect_to` path and sending the browser there — is an open
     * redirect: anything that reflects a submitted URL into a `Location` header will eventually send
     * somebody to a site that looks like this one. Route NAMES cannot express an off-site target at
     * all, so the worst a crafted value can do here is land on the picker.
     *
     * The pages that carry the button. Each names only the arguments its own route actually takes, so
     * a value for one page cannot be smuggled onto another.
     *
     * The picker deliberately posts **no** `return` field: its button predates this, and the default
     * keeps its behaviour — and its test — exactly as it was.
     *
     * @return array{string, array<string, scalar|null>}
     */
    private function target(ServerRequestInterface $request): array
    {
        $form = FormData::fromRequest($request);
        $requested = $form->string('return');

        if ($requested === OrderTestingReturn::STORE) {
            $sourceId = $this->positiveInt($form->string('sourceId'));

            // Only a real store id can rebuild that URL; without one the generator would throw, so
            // this falls back rather than failing a sync that actually succeeded.
            if ($sourceId !== null) {
                return [OrderTestingReturn::STORE, ['sourceId' => $sourceId]];
            }
        }

        // The Practice Orders store page, which takes the same single argument as the Order Testing
        // one. Kept as its own branch rather than folded in with STORE, so each name still declares
        // the arguments its own route accepts.
        if ($requested === OrderTestingReturn::PRACTICE_STORE) {
            $sourceId = $this->positiveInt($form->string('sourceId'));

            if ($sourceId !== null) {
                return [OrderTestingReturn::PRACTICE_STORE, ['sourceId' => $sourceId]];
            }

            return [OrderTestingReturn::PRACTICE_PICKER, []];
        }

        if ($requested === OrderTestingReturn::PRACTICE_PICKER) {
            return [OrderTestingReturn::PRACTICE_PICKER, []];
        }

        if ($requested === OrderTestingReturn::CALL_RECORDINGS) {
            // The filters that page reads. Carried so a sync does not throw away the store and day
            // the operator had loaded — `load` included, or the table would come back empty.
            $filters = [];

            foreach (['store', 'load', 'date', 'source'] as $key) {
                $value = $form->string($key);

                if ($value !== '') {
                    $filters[$key] = $value;
                }
            }

            return [OrderTestingReturn::CALL_RECORDINGS, $filters];
        }

        return [OrderTestingReturn::PICKER, []];
    }

    /** Digits only and bounded; anything else is refused rather than cast into a different number. */
    private function positiveInt(string $raw): ?int
    {
        return preg_match('/^[1-9][0-9]{0,18}$/', $raw) === 1 ? (int) $raw : null;
    }
}
