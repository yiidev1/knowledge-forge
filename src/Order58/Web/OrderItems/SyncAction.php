<?php

declare(strict_types=1);

namespace App\Order58\Web\OrderItems;

use App\Order58\Application\Items\ItemSyncResult;
use App\Order58\Application\Items\OrderItemSyncInterface;
use App\Order58\Domain\Orders\Order58OrderRepositoryInterface;
use App\Order58\Domain\Orders\OrderListQuery;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\FormData;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function in_array;
use function preg_match;
use function sprintf;

/**
 * "Sync Order Items" — fetch one original order's items now, from whichever page asked.
 *
 * ## One action, two pages
 *
 * The Orders listing and the original-vs-demo comparison page both post here. They are the same
 * operation — one account, one order, fetch its items — differing only in where the operator should
 * end up afterwards, which is an allow-listed `return` field. Two actions would have been two places
 * to get ownership checking right.
 *
 * It also has to be here rather than in the Order Testing module: `OrderTestingBoundariesTest` forbids
 * any file under `src/OrderTesting/` from naming `App\Order58`, so the comparison page reaches this by
 * **route name**. A name is not a namespace, and nothing about module isolation is relaxed.
 *
 * ## Ownership is proved, not assumed
 *
 * `account_id` and `source_order_id` both arrive in the request body, so neither can be trusted. The
 * order must already exist in `order58_orders` **for that account** before a single byte goes to the
 * provider. Without that check this endpoint would fetch any order id anybody typed, on any account —
 * which is both a data leak and a way to spend somebody else's API quota.
 *
 * ## Nothing here touches a demo order
 *
 * The comparison page shows two orders side by side, and only the left one is ours to refresh. This
 * action never reads or writes `order_testing_demo_orders`; the demo id travels in the request solely
 * so the operator can be returned to the comparison they were looking at.
 *
 * ## The lease decides what a second click means
 *
 * {@see OrderItemSyncInterface::syncOrder()} with `manual: true` widens the claim to include `QUEUED`,
 * so pressing the button on an order the worker has merely queued takes it immediately rather than
 * waiting for the next tick. An order already `PROCESSING` under a live lease fails the claim, and the
 * operator is told so — **with no second request to the provider**.
 */
final readonly class SyncAction
{
    /**
     * Where an operator may be sent back to.
     *
     * Allow-listed so the return value can never become an open redirect — the same rule
     * {@see \App\Order58\Web\DataManagement\SyncAction} already applies.
     */
    private const RETURNS = ['order58.orders', 'order-testing.demo-compare'];

    public function __construct(
        private OrderItemSyncInterface $sync,
        private Order58OrderRepositoryInterface $orders,
        private FlashMessages $flash,
        private Redirect $redirect,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $form = FormData::fromRequest($request);

        $accountId = $this->positiveInt($form->string('account_id'));
        $sourceOrderId = $this->positiveInt($form->string('source_order_id'));

        [$route, $arguments] = $this->returnTarget($form, $accountId, $sourceOrderId);

        if ($accountId === null || $sourceOrderId === null) {
            $this->flash->error('That is not an order this page can synchronise.');

            return $this->redirect->afterPost($route, $arguments);
        }

        // The authorization boundary: the order must be one THIS account already has mirrored.
        if (!$this->ownsOrder($accountId, $sourceOrderId)) {
            // Deliberately the same message as a malformed id. An operator who may not see this order
            // learns nothing about whether it exists.
            $this->flash->error('That is not an order this page can synchronise.');

            return $this->redirect->afterPost($route, $arguments);
        }

        $outcome = $this->sync->syncOrder($accountId, $sourceOrderId, manual: true);

        match ($outcome->result) {
            ItemSyncResult::Synced => $this->flash->success(sprintf(
                'Original order items synced successfully — %d item%s.',
                $outcome->itemCount,
                $outcome->itemCount === 1 ? '' : 's',
            )),
            // A verified empty order is a success with nothing in it, and is comparable. Saying
            // "synced, 0 items" would read as a failure that happened to be quiet.
            ItemSyncResult::Empty => $this->flash->success(
                'Original order synchronized successfully. No items returned.',
            ),
            ItemSyncResult::Unchanged => $this->flash->info(sprintf(
                'Original order items are already up to date — %d item%s.',
                $outcome->itemCount,
                $outcome->itemCount === 1 ? '' : 's',
            )),
            // Neither a success nor an error: somebody else — the worker, or another administrator —
            // holds this order, and no request was made.
            ItemSyncResult::Busy => $this->flash->warning(
                'Synchronization is already in progress for this order.',
            ),
            // Not an error either, and emphatically not a failed sync: the feature is switched off or
            // has never been configured. The order was not claimed and nothing about it changed, so
            // the message is about the installation rather than about this order.
            ItemSyncResult::Unavailable => $this->flash->warning((string) $outcome->error),
            ItemSyncResult::Failed => $this->flash->error(
                'Could not synchronize original order items. Previously saved items were preserved. '
                . (string) $outcome->error,
            ),
        };

        return $this->redirect->afterPost($route, $arguments);
    }

    /**
     * Where to send the operator, and with what.
     *
     * The comparison page needs three route arguments to rebuild its URL; the orders listing needs its
     * filters carried back so a sync does not silently reset the page somebody was reading. Anything
     * not on the allow-list falls back to the orders listing.
     *
     * @return array{string, array<string, scalar|null>}
     */
    private function returnTarget(FormData $form, ?int $accountId, ?int $sourceOrderId): array
    {
        $requested = $form->string('return');

        if ($requested === 'order-testing.demo-compare') {
            $demoOrderId = $this->positiveInt($form->string('demo_order_id'));

            // Only a complete triple can rebuild that URL. Anything missing goes to the listing
            // rather than to a route generator that would throw.
            if ($accountId !== null && $sourceOrderId !== null && $demoOrderId !== null) {
                return ['order-testing.demo-compare', [
                    'sourceId' => $accountId,
                    'sourceOrderId' => $sourceOrderId,
                    'demoOrderId' => $demoOrderId,
                ]];
            }
        }

        if (!in_array($requested, self::RETURNS, true)) {
            $requested = 'order58.orders';
        }

        // The listing's filters, carried back exactly as its own pager builds them, so a sync does not
        // throw away the store, order id, status, date range or page the operator was looking at.
        // This list must stay in step with the query keys {@see \App\Order58\Web\Orders\Action} reads.
        $filters = [];
        foreach (['account_id', 'order_id', 'status', 'list_from', 'list_to', 'page'] as $key) {
            $value = $form->string($key);

            if ($value !== '') {
                $filters[$key] = $value;
            }
        }

        return ['order58.orders', $filters];
    }

    /** Whether this account already has this order mirrored locally. */
    private function ownsOrder(int $accountId, int $sourceOrderId): bool
    {
        $page = $this->orders->page(new OrderListQuery(
            accountId: $accountId,
            sourceOrderId: $sourceOrderId,
        ));

        return ($page['total'] ?? 0) > 0;
    }

    /** Digits only and bounded; anything else is refused rather than cast into a different number. */
    private function positiveInt(string $raw): ?int
    {
        return preg_match('/^[1-9][0-9]{0,18}$/', $raw) === 1 ? (int) $raw : null;
    }
}
