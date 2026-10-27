<?php

declare(strict_types=1);

namespace App\Order58\Web\CallRecordings;

use App\Order58\Application\CaseOrders\CaseOrderSyncCoordinator;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function is_array;
use function is_string;
use function preg_match;

/**
 * Load Calls (POST …/call-recordings/load).
 *
 * ## Why this is a POST that redirects, when the page it returns to is a GET
 *
 * Loading a store's calls may now *start* something — one global case order synchronisation — and a
 * GET that starts work is a GET a refresh repeats, a crawler triggers and a browser prefetches. So the
 * press is a POST, and it immediately redirects to the readable address the page has always had. The
 * result page stays bookmarkable, a refresh re-reads rather than re-posts, and nothing is triggered by
 * opening, filtering or polling.
 *
 * ## What it does NOT do
 *
 * Wait. The coordinator claims a run and returns; the fetch and the import happen in the worker. So
 * this request is as fast as a redirect whatever the provider is doing, and a slow or unreachable
 * provider cannot leave a browser loading.
 *
 * ## The case order sync is global, and the selected store is none of its business
 *
 * The endpoint takes no account id and no date range — it answers for everything. The store chosen
 * here scopes the *recordings* that the next page shows, and is deliberately not passed to the
 * coordinator: filtering a global call per store is how one provider request becomes fifty.
 *
 * ## A failure here never costs the recordings
 *
 * Whatever the coordinator says — started, joined, fresh, rate-limited or not configured — the
 * redirect happens and the recordings load from what is already stored. Case orders are a convenience
 * for linking; they are not a precondition for managing audio.
 */
final readonly class LoadAction
{
    public function __construct(
        private CaseOrderSyncCoordinator $coordinator,
        private Redirect $redirect,
        private FlashMessages $flash,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        $store = $this->digits($body['store'] ?? null);
        $date = $this->date($body['date'] ?? null);
        $source = is_string($body['source'] ?? null) ? (string) $body['source'] : '';

        if ($store === null) {
            // Nothing chosen: back to the picker, with no synchronisation asked for.
            $this->flash->info('Choose a store to load its calls.');

            return $this->redirect->afterPost('order58.call-recordings');
        }

        /*
         * Asked for once, globally, before the page that shows the result.
         *
         * `manual: false` on purpose — this is an automatic caller, so it honours both the freshness
         * window and the auto-sync switch. Only the button on the page is a manual instruction.
         */
        $decision = $this->coordinator->request('load-calls');

        // Every outcome is information, never an error that stops the page: the recordings load either
        // way. "started" and "joined" are both normal, and so is reusing data that is already fresh.
        if ($decision->isError()) {
            $this->flash->warning(
                'Case orders were not refreshed: ' . $decision->message
                    . ' The calls below are loaded from the data already stored.',
            );
        } elseif ($decision->startedWork()) {
            $this->flash->info('Case orders are being refreshed in the background. ' . $decision->message);
        } else {
            $this->flash->info($decision->message);
        }

        return $this->redirect->afterPost('order58.call-recordings', ['store' => $store, 'load' => '1']
            + ($date === '' ? [] : ['date' => $date])
            + ($source === '' ? [] : ['source' => $source]));
    }

    /** Digits only and bounded, or null. Never cast into a different number. */
    private function digits(mixed $raw): ?string
    {
        return is_string($raw) && preg_match('/^[1-9][0-9]{0,18}$/', $raw) === 1 ? $raw : null;
    }

    /** `YYYY-MM-DD` as the form offers it, or empty so the page falls back to its own default. */
    private function date(mixed $raw): string
    {
        return is_string($raw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1 ? $raw : '';
    }
}
