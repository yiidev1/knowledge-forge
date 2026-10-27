<?php

declare(strict_types=1);

namespace App\Order58\Web\CaseOrders;

use App\Order58\Application\CaseOrders\CaseOrderSyncCoordinator;
use App\Order58\Application\CaseOrders\CaseOrderSyncService;
use App\Order58\Domain\CaseOrders\CaseOrderSyncRun;
use App\Order58\Domain\CaseOrders\CaseOrderSyncStatus;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function is_array;
use function is_string;
use function json_encode;
use function preg_match;
use function str_contains;

use const JSON_THROW_ON_ERROR;

/**
 * Synchronise case orders now (POST …/case-orders/sync).
 *
 * ## Why this does the work rather than queueing it
 *
 * The operator pressed a button and wants the real counts, not a job id. There is also nothing for a
 * worker to retry — a repeat costs another provider request, so a background retry would either do
 * nothing or ask the provider twice for the same thing.
 *
 * It was briefly enqueue-only: this action claimed a run and left the fetch to
 * {@see \App\Order58\Application\CaseOrders\CaseOrderSyncDrainer} inside `kf:worker:run`. That is
 * correct only where something actually runs that command. Where nothing does, the press claimed a run
 * that was never executed, so the orders never arrived and the next press was refused for a run that
 * was not going anywhere. Claiming and executing in the one request removes that dependency entirely.
 *
 * The drainer is deliberately left registered. It executes the same claim through the same service, so
 * a host that does run the worker keeps a second way to finish a run this request abandoned — and that
 * is the only thing it is now for.
 *
 * The run row is what makes a slow request bearable. Every stage is written before the next begins, so
 * {@see StatusAction} can draw an honest bar while this request is still working — which is exactly
 * what the dialog's script polls for — and an operator whose browser gave up still learns what
 * happened when the page reloads.
 *
 * ## It answers the caller in the caller's own terms
 *
 * The dialog asks for JSON and is told, in one reply, which run was started and how it finished. That
 * is the whole of the progress problem for a request that does the work itself: there is nothing to
 * discover, because the answer arrives with the response. Polling had to guess which run it was
 * watching, and a poll that landed before the claim was written reported "no run" — which the dialog
 * could only read as "nothing is happening", leaving it on `Starting…` for ever.
 *
 * Without the JSON header it still redirects, so the form keeps working with no script at all.
 *
 * ## Pressing twice is safe, and it is the database that makes it safe
 *
 * The service claims the day with an `INSERT` against a unique index. Two simultaneous presses race on
 * that index and exactly one wins; the loser is told "already running" and shown the same progress.
 * Nothing here needs a check, which is the point — a check would have a window.
 *
 * ## Re-importing costs nothing, and is offered only when it is free
 *
 * A run that reached the provider and then failed locally has its sanitized response staged. Posting
 * `reimport=<run id>` processes that again without calling anybody. A run that never reached the
 * provider has nothing staged and is simply retried as a normal sync.
 */
final readonly class SyncAction
{
    public function __construct(
        /** Decides and claims. It never executes, so the rules stay in one place. */
        private CaseOrderSyncCoordinator $coordinator,
        /** Executes the claim, and re-imports a staged response without asking the provider. */
        private CaseOrderSyncService $sync,
        private Redirect $redirect,
        private FlashMessages $flash,
        private LoggerInterface $logger,
        private ResponseFactoryInterface $responseFactory,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $reimport = $this->runId(is_array($body) ? ($body['reimport'] ?? null) : null);

        if ($reimport !== null) {
            // A local re-import of a response already in hand. No provider request, so it runs here.
            $outcome = $this->sync->resume($reimport);

            $outcome->isSuccess()
                ? $this->flash->success('Case orders imported again. ' . $outcome->message)
                : $this->flash->error($outcome->message);

            if ($this->wantsJson($request)) {
                return $this->json($outcome->run, $outcome->message, $outcome->isSuccess());
            }

            // Named for the same reason the sync is: the operator asked about this run, so the panel
            // they come back to describes it rather than whichever run happens to be newest.
            return $this->redirect->afterPost(
                'order58.call-recordings',
                ['sync-run' => (string) $reimport],
            );
        }

        /*
         * Claim, then do the work. The coordinator decides; this action carries it out.
         *
         * So this POST lasts as long as the provider does. The dialog is built for that: its panel
         * polls {@see StatusAction} from the moment the button is pressed, and every stage is written
         * to the run before the next begins — so the bar moves while this request is still open. A
         * browser that gives up has cancelled nothing, because the run is a row either way.
         */
        $decision = $this->coordinator->request('manual', manual: true);

        if ($decision->isError()) {
            if ($this->wantsJson($request)) {
                return $this->json(null, $decision->message, false);
            }

            $this->flash->error($decision->message);

            return $this->redirect->afterPost('order58.call-recordings');
        }

        /*
         * This request claimed the run, so this request finishes it.
         *
         * Only when it claimed. `joined` means another request is already inside `execute()` for the
         * same run — running it again here would ask the provider a second time for one press — and
         * `fresh` and `rate_limited` started nothing at all. Each of those is reported, not retried.
         */
        if ($decision->startedWork() && $decision->run !== null) {
            $outcome = $this->runNow($decision->run);

            if ($this->wantsJson($request)) {
                // The run is re-read, so the counts reported are the ones actually written.
                return $this->json(
                    $this->coordinator->find($decision->run->id),
                    $outcome['message'],
                    $outcome['ok'],
                );
            }

            /*
             * Come back to THIS run, named explicitly.
             *
             * Without the id the page had to guess which run the panel should describe, and guessing
             * meant "the most recent" — which is how a previous synchronisation's counts ended up
             * presented as the current one. The id makes the panel's subject a fact rather than an
             * inference, and it is this operator's own run by construction.
             */
            return $this->redirect->afterPost(
                'order58.call-recordings',
                ['sync-run' => (string) $decision->run->id],
            );
        }

        // Joined, fresh or rate-limited: none is a fault, and none started anything here.
        if ($this->wantsJson($request)) {
            return $this->json($decision->run, $decision->message, true);
        }

        $this->flash->info($decision->message);

        // A joined run IS the caller's subject — they pressed, and this is the run they get — so it
        // is named too. Fresh and rate-limited started nothing, so they name nothing.
        return $decision->isWatchable() && $decision->run !== null
            ? $this->redirect->afterPost(
                'order58.call-recordings',
                ['sync-run' => (string) $decision->run->id],
            )
            : $this->redirect->afterPost('order58.call-recordings');
    }

    /**
     * Fetch and import the run this request claimed, and say what happened.
     *
     * The guard and the error handling are the drainer's, deliberately: both paths execute the same
     * claim through the same service, so a difference between them would be a difference in what the
     * provider is asked, not merely in wording.
     *
     * A `Throwable` escaping the service leaves the run CLAIMED rather than FAILED. That is wanted —
     * the lease decides, and recovery closes it as abandoned, which counts the request as possibly
     * spent. Guessing "it never went out" here is the one error that would cost a second request.
     */
    /** @return array{ok: bool, message: string} */
    private function runNow(CaseOrderSyncRun $run): array
    {
        if ($run->status !== CaseOrderSyncStatus::Claimed) {
            // Somebody else moved it on between the claim and here. Theirs to finish, not ours.
            $message = 'A case order synchronisation is already running.';
            $this->flash->info($message);

            return ['ok' => true, 'message' => $message];
        }

        try {
            $outcome = $this->sync->execute($run);
        } catch (Throwable $e) {
            // The class name only: a driver or transport message can carry a credential or an order.
            $this->logger->error('Case order synchronisation failed in the web request.', [
                'reason' => 'order58_case_order_sync_failed',
                'run' => $run->id,
                'error_class' => $e::class,
            ]);

            $message = 'The case order synchronisation stopped unexpectedly. The run is recorded; '
                . 'nothing was retried automatically.';
            $this->flash->error($message);

            return ['ok' => false, 'message' => $message];
        }

        $outcome->isSuccess()
            ? $this->flash->success($outcome->message)
            : $this->flash->error($outcome->message);

        return ['ok' => $outcome->isSuccess(), 'message' => $outcome->message];
    }

    /**
     * Whether the caller asked for JSON rather than a page.
     *
     * The dialog's script sets both headers; a browser following the form sets neither. Deciding by
     * the request rather than by a route keeps one endpoint, one authorization and one set of rules
     * for what a press does — the two differ only in how the answer is written down.
     */
    private function wantsJson(ServerRequestInterface $request): bool
    {
        return $request->getHeaderLine('X-Requested-With') === 'fetch'
            || str_contains($request->getHeaderLine('Accept'), 'application/json');
    }

    /**
     * The run as the dialog needs it: which run, how it ended, and what it actually wrote.
     *
     * The counts come from the run row rather than from the importer's return value, so what the
     * modal shows is what was committed. A run that is null — refused before anything was claimed —
     * still carries a message, because "nothing started, and here is why" is the answer in that case.
     */
    private function json(?CaseOrderSyncRun $run, string $message, bool $ok): ResponseInterface
    {
        $payload = [
            'success' => $ok,
            'message' => $message,
            'hasRun' => $run !== null,
            // The same shape the status endpoint answers with, so the panel renders either reply.
            'run' => CaseOrderRunView::describe($run),
        ];

        $response = $this->responseFactory
            ->createResponse(200)
            ->withHeader('Content-Type', 'application/json; charset=UTF-8')
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->withHeader('X-Content-Type-Options', 'nosniff');

        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));

        return $response;
    }

    /** Digits only and bounded, or null; anything else is not cast into a different number. */
    private function runId(mixed $raw): ?int
    {
        return is_string($raw) && preg_match('/^[1-9][0-9]{0,18}$/', $raw) === 1 ? (int) $raw : null;
    }
}
