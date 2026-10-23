<?php

declare(strict_types=1);

namespace App\OrderTesting\Web\Receipt;

use App\OrderTesting\Application\Comparison\DemoOrderNormalizer;
use App\OrderTesting\Application\Comparison\OrderComparator;
use App\OrderTesting\Application\Comparison\ReceiptComparison;
use App\OrderTesting\Application\Practice\PracticeResultVisibility;
use App\OrderTesting\Domain\DemoOrder;
use App\OrderTesting\Domain\Comparison\OriginalItemsReaderInterface;
use App\OrderTesting\Domain\DemoOrderRepositoryInterface;
use App\OrderTesting\Domain\SourceOrderReaderInterface;
use App\OrderTesting\Domain\StoreReaderInterface;
use App\Shared\Domain\Exception\NotFoundException;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * Two receipts, side by side, and what differs between them.
 *
 * ## Two complete documents, not one document built from both
 *
 * The left receipt is the original order and the right one is the demo order, and each is rendered from
 * its own {@see \App\OrderTesting\Domain\Comparison\NormalizedOrder}. Neither borrows a value from the
 * other to fill a gap: an original with no customer name prints a dash, even when the trainee entered
 * one, because the question this page answers is what the two documents each say.
 *
 * ## The comparison is the one the rest of the module already uses
 *
 * {@see OrderComparator} pairs the lines and judges every field, and this page reads its result rather
 * than forming a second opinion. That matters beyond saving code: the comparison page, this page and
 * any later scoring pass must agree about what differs, and they do because there is one comparator.
 * Item pairing in particular is the provider's catalogue id first and the product name only as a
 * documented fallback, never array position, and a demo line can be claimed by at most one original.
 *
 * ## The same three ids, and the same boundary
 *
 * Store, source order and demo order all come from the route and all three go into
 * {@see DemoOrderRepositoryInterface::findOne()}. A demo order belonging to another store or another
 * source order is not found, so the lookup is the ownership check rather than something a later line
 * has to remember to assert.
 *
 * ## Read-only
 *
 * Four bounded reads and nothing else. No provider is contacted, no stored amount is recalculated and
 * nothing is written — printing this page cannot change an order.
 */
/*
 * Not `final`: the agent practice surface renders this same page from its own route group, and
 * extends this class to supply its own addresses and shell rather than restating the action.
 */
readonly class Action
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private DemoOrderRepositoryInterface $demoOrders,
        private SourceOrderReaderInterface $sourceOrders,
        private OriginalItemsReaderInterface $originalItems,
        private StoreReaderInterface $stores,
        private DemoOrderNormalizer $demoNormalizer,
        private OrderComparator $comparator,
        /*
         * Whether this demo order is the reader's own.
         *
         * Held, not consulted, by this surface, for the reason given on the comparison page beside
         * it: a manager prints anybody's receipt. The practice surface overrides {@see admits()}.
         */
        protected PracticeResultVisibility $visibility,
    ) {}

    public function __invoke(
        #[RouteArgument]
        int $sourceId,
        #[RouteArgument]
        int $sourceOrderId,
        #[RouteArgument]
        int $demoOrderId,
    ): ResponseInterface {
        $storeName = $this->stores->nameOf($sourceId);

        if ($storeName === null) {
            throw new NotFoundException('store_not_found', 'No store has that source id.');
        }

        $demo = $this->demoOrders->findOne($sourceId, $sourceOrderId, $demoOrderId);

        if ($demo === null) {
            throw new NotFoundException(
                'demo_order_not_found',
                'No demo order with that id belongs to this store and source order.',
            );
        }

        // Asked before the receipt is built, and answered with the same words as a missing row: the
        // id is in the path, so a reader who may not see it must not learn that it exists.
        if (!$this->admits($demo)) {
            throw new NotFoundException(
                'demo_order_not_found',
                'No demo order with that id belongs to this store and source order.',
            );
        }

        $comparison = $this->comparator->compare(
            $this->sourceOrders->find($sourceId, $sourceOrderId),
            $this->demoNormalizer->normalize($demo),
            // Local tables only, and two bounded queries. Rendering a receipt never calls the provider:
            // the item fetch is a deliberate action elsewhere, with its own lock and lease.
            $this->originalItems->read($sourceId, $sourceOrderId),
        );

        return $this->viewRenderer
            ->withLayout($this->layoutPath())
            ->render(__DIR__ . '/template', [
                'routes' => $this->surfaceRoutes(),
                'sourceId' => $sourceId,
                'storeName' => $storeName,
                'demo' => $demo,
                'receipt' => ReceiptComparison::from($comparison),
            ]);
    }

    /**
     * Whether this surface may print this demo order for its reader.
     *
     * True here: a trainer may print any practitioner's receipt. The practice surface answers for
     * one person only.
     */
    protected function admits(DemoOrder $demo): bool
    {
        return true;
    }

    /**
     * The addresses this page builds its own links from.
     *
     * Order Testing's, here. The agent practice surface renders the same page from its own group and
     * supplies its own — without this it drew Order Testing links, which that realm's gate refuses, so
     * Compare simply did not go anywhere.
     *
     * Route names, not classes: a name is not a namespace, so this module names nobody.
     *
     * @return array<string, string>
     */
    protected function surfaceRoutes(): array
    {
        return [
            'page' => 'order-testing',
            'pageLabel' => 'Manage Orders',
            'store' => 'order-testing.store',
            'demoOrders' => 'order-testing.demo-orders',
            'demoCompare' => 'order-testing.demo-compare',
            'demoReceipt' => 'order-testing.demo-receipt',
        ];
    }

    /** The shell this page is drawn inside. The agent surface has its own. */
    protected function layoutPath(): string
    {
        return '@src/Web/Shared/Layout/Admin/layout.php';
    }

}
