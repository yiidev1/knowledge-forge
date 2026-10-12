<?php

declare(strict_types=1);

namespace App\Order58\Web\Orders;

use App\Order58\Application\Orders\OrderDateRange;
use App\Order58\Application\Orders\OrderSyncInProgress;
use App\Order58\Application\Orders\OrderSyncService;
use App\Order58\Client\Orders\OrderDataFailed;
use App\Order58\Domain\StoreDirectoryQuery;
use App\Order58\Domain\StoreDirectoryReaderInterface;
use App\Order58\Domain\StoreSourceStatusFilter;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\FormData;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function sprintf;

/**
 * Runs one sync (POST /admin/order58/orders/sync) and reports what it did.
 *
 * Thin on purpose: validate, call one service, turn its summary into a sentence. Every decision about
 * what to fetch, map or write belongs to {@see OrderSyncService}.
 *
 * ## The lock moved into the service, and this class stopped taking one
 *
 * Two operators syncing the same store and range at once would both fetch, both map and both write. The
 * unique key keeps the *table* correct, but it does not stop the second run doing all the work twice, and
 * it does not stop the two interleaving so that each reports counts describing a state neither produced.
 *
 * That lock used to live here — which left the OTHER caller unprotected:
 * `kf:order58:import-recordings` calls the service directly after a settled recording batch and
 * acquired nothing. It now lives in {@see \App\Order58\Application\Orders\OrderSyncLock}, held by
 * the service for the whole run, so both callers are covered by construction.
 *
 * This action no longer acquires anything, so the lock is taken exactly once per request and there is
 * no nesting. A refusal arrives as {@see OrderSyncInProgress} and is reported in the same words as
 * before.
 */
final readonly class SyncAction
{
    public function __construct(
        private OrderSyncService $sync,
        private StoreDirectoryReaderInterface $stores,
        private Redirect $redirect,
        private FlashMessages $flash,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $form = FormData::fromRequest($request);

        $accountId = (int) $form->string('account_id');
        $from = $form->string('date_from');
        $to = $form->string('date_to');

        // Carried back on every outcome so a refused sync does not also clear the operator's choices.
        // Declared, because the closure below captures it by reference and is built before the sync
        // runs: without this Psalm narrows it to `null` and calls the later check impossible.
        /** @var int|null $batchId */
        $batchId = null;

        // Carried back on every outcome so a refused sync does not also clear the operator's choices,
        // and so the progress panel knows which operation to poll.
        $back = static function () use (&$batchId, $accountId, $from, $to): array {
            $carried = [
                'form_account_id' => (string) $accountId,
                'form_from' => $from,
                'form_to' => $to,
                'account_id' => (string) $accountId,
            ];

            if ($batchId !== null) {
                $carried['batch'] = (string) $batchId;
            }

            return $carried;
        };

        if ($accountId <= 0 || !$this->storeExists($accountId)) {
            $this->flash->error('Choose a store from the list.');

            return $this->redirect->afterPost('order58.orders');
        }

        [$range, $error] = OrderDateRange::create($from, $to);

        if ($range === null) {
            $this->flash->error((string) $error);

            return $this->redirect->afterPost('order58.orders', $back());
        }

        try {
            $summary = $this->sync->sync($accountId, $range);
            $batchId = $summary->batchId;
        } catch (OrderSyncInProgress) {
            // Somebody else — another administrator, or the recording worker — holds this store and
            // range. The same sentence this page has always shown; the service releases its own lock.
            $this->flash->error('That store and date range is already being synchronised. Wait for it to finish.');

            return $this->redirect->afterPost('order58.orders', $back());
        } catch (OrderDataFailed $e) {
            // Nothing was written: the client fails before the first order is mapped.
            $this->flash->error($e->getMessage());

            return $this->redirect->afterPost('order58.orders', $back());
        }

        $counts = sprintf(
            '%d received · %d created · %d updated · %d unchanged · %d failed',
            $summary->received,
            $summary->created,
            $summary->updated,
            $summary->unchanged,
            $summary->failed,
        );

        if ($summary->stoppedEarly) {
            // Never reported as success. A run that ran out of time saved real rows, and saying so is the
            // only way the operator knows to narrow the range rather than assume the store is empty.
            $this->flash->error(
                'Stopped before finishing: this sync ran out of time. ' . $counts
                . '. The orders already saved are correct — narrow the date range and run it again.',
            );
        } elseif ($summary->failed > 0) {
            $this->flash->error('Finished with problems — ' . $counts . '.');
        } else {
            $this->flash->success('Synchronised ' . $range->label() . ' — ' . $counts . '.');
        }

        return $this->redirect->afterPost('order58.orders', $back());
    }

    private function storeExists(int $accountId): bool
    {
        $result = $this->stores->search(new StoreDirectoryQuery(
            perPage: 1000,
            sourceStatus: StoreSourceStatusFilter::Active,
        ));

        foreach ($result->items as $store) {
            if ($store->sourceId === $accountId) {
                return true;
            }
        }

        return false;
    }
}
