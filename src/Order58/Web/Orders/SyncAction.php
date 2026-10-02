<?php

declare(strict_types=1);

namespace App\Order58\Web\Orders;

use App\Order58\Application\Orders\OrderDateRange;
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
use Yiisoft\Db\Connection\ConnectionInterface;

use function sprintf;

/**
 * Runs one sync (POST /admin/order58/orders/sync) and reports what it did.
 *
 * Thin on purpose: validate, take a lock, call one service, turn its summary into a sentence. Every
 * decision about what to fetch, map or write belongs to {@see OrderSyncService}.
 *
 * ## The lock, and why idempotency is not a substitute for it
 *
 * Two operators syncing the same store and range at once would both fetch, both map and both write. The
 * unique key keeps the *table* correct, but it does not stop the second run doing all the work twice, and
 * it does not stop the two interleaving so that each reports counts describing a state neither produced.
 * So the second request is refused outright, by a MySQL advisory lock keyed on the exact store and range.
 *
 * `GET_LOCK` rather than a table: it is released when the connection ends, including when PHP dies
 * mid-request, so a crashed sync cannot leave a flag that blocks every future one.
 */
final readonly class SyncAction
{
    public function __construct(
        private OrderSyncService $sync,
        private StoreDirectoryReaderInterface $stores,
        private ConnectionInterface $connection,
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
        $back = static fn(): array => [
            'form_account_id' => (string) $accountId,
            'form_from' => $from,
            'form_to' => $to,
            'account_id' => (string) $accountId,
        ];

        if ($accountId <= 0 || !$this->storeExists($accountId)) {
            $this->flash->error('Choose a store from the list.');

            return $this->redirect->afterPost('order58.orders');
        }

        [$range, $error] = OrderDateRange::create($from, $to);

        if ($range === null) {
            $this->flash->error((string) $error);

            return $this->redirect->afterPost('order58.orders', $back());
        }

        $lock = sprintf('kf-o58-orders-%d-%s-%s', $accountId, $range->from, $range->to);

        if (!$this->acquire($lock)) {
            $this->flash->error('That store and date range is already being synchronised. Wait for it to finish.');

            return $this->redirect->afterPost('order58.orders', $back());
        }

        try {
            $summary = $this->sync->sync($accountId, $range);
        } catch (OrderDataFailed $e) {
            // Nothing was written: the client fails before the first order is mapped.
            $this->flash->error($e->getMessage());

            return $this->redirect->afterPost('order58.orders', $back());
        } finally {
            $this->release($lock);
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

    /** Zero timeout: a second request is refused immediately rather than queueing behind the first. */
    private function acquire(string $name): bool
    {
        return (int) $this->connection
            ->createCommand('SELECT GET_LOCK(:name, 0)', [':name' => $name])
            ->queryScalar() === 1;
    }

    private function release(string $name): void
    {
        $this->connection->createCommand('SELECT RELEASE_LOCK(:name)', [':name' => $name])->queryScalar();
    }
}
