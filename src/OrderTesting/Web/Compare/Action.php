<?php

declare(strict_types=1);

namespace App\OrderTesting\Web\Compare;

use App\OrderTesting\Application\Comparison\DemoOrderNormalizer;
use App\OrderTesting\Application\Comparison\OrderComparator;
use App\OrderTesting\Domain\Comparison\OriginalItemsReaderInterface;
use App\OrderTesting\Domain\DemoOrderRepositoryInterface;
use App\OrderTesting\Domain\SourceOrderReaderInterface;
use App\OrderTesting\Domain\StoreReaderInterface;
use App\OrderTesting\Domain\TestAttemptRepositoryInterface;
use App\Shared\Domain\Exception\NotFoundException;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * One demo order beside the source order it was recreated from.
 *
 * ## All three ids, and the lookup is the boundary
 *
 * Store, source order and demo order all come from the route and all three go into
 * {@see DemoOrderRepositoryInterface::findOne()}. Changing any one of them to another merchant's value
 * produces the same 404 as an id that never existed — the narrow lookup is what enforces that, not a
 * check afterwards that somebody could forget to write.
 *
 * ## The items are read separately, and may legitimately be unknown
 *
 * The original order comes from the orders mirror; its line items do not live in that payload at all
 * and come from their own table, filled by a sync somebody asked for. So "mirrored" and "items known"
 * are two different states, and the second is read on its own. Without that, an order whose items have
 * never been fetched would look like an order with an empty basket, and every line the trainee entered
 * would be reported as invented.
 *
 * ## The left-hand side may legitimately be empty
 *
 * The demo order is a file somebody's listener wrote; the source order is mirrored from an API over a
 * date range somebody asked for. The second can be absent while the first exists, and that is not an
 * error — the page says the mirror does not hold it and still shows the trainee's work in full. See
 * {@see OrderComparator}.
 *
 * ## No score
 *
 * This page renders an {@see \App\OrderTesting\Domain\Comparison\OrderComparison}: two values and a
 * status per field, and nothing that adds them up. Scoring is a separate, separately specified piece of
 * work and will read that object rather than this template.
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
        private TestAttemptRepositoryInterface $attempts,
        private StoreReaderInterface $stores,
        private DemoOrderNormalizer $demoNormalizer,
        private OrderComparator $comparator,
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

        $attempt = $demo->matchedAttemptId === null
            ? null
            : ($this->attempts->byIds([$demo->matchedAttemptId])[$demo->matchedAttemptId] ?? null);

        return $this->viewRenderer
            ->withLayout($this->layoutPath())
            ->render(__DIR__ . '/template', [
                'routes' => $this->surfaceRoutes(),
                'canSyncItems' => $this->canSyncItems(),
                'sourceId' => $sourceId,
                'storeName' => $storeName,
                'demo' => $demo,
                'attempt' => $attempt,
                'comparison' => $this->comparator->compare(
                    $this->sourceOrders->find($sourceId, $sourceOrderId),
                    $this->demoNormalizer->normalize($demo),
                    // Local tables only, and two bounded queries. Rendering a comparison never calls
                    // the provider: the item fetch is a deliberate action with its own lock and lease.
                    $this->originalItems->read($sourceId, $sourceOrderId),
                ),
            ]);
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

    /**
     * May this surface's reader re-import the original order's items?
     *
     * A management action — it talks to Order58 and rewrites mirrored rows — so it belongs with the
     * realms that manage. The agent surface answers no, and the form is not rendered at all.
     */
    protected function canSyncItems(): bool
    {
        return true;
    }

    /** The shell this page is drawn inside. The agent surface has its own. */
    protected function layoutPath(): string
    {
        return '@src/Web/Shared/Layout/Admin/layout.php';
    }

}
