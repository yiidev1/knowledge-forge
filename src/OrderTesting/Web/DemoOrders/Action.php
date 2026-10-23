<?php

declare(strict_types=1);

namespace App\OrderTesting\Web\DemoOrders;

use App\OrderTesting\Application\Practice\PracticeResultVisibility;
use App\OrderTesting\Domain\DemoOrder;
use App\OrderTesting\Domain\DemoOrderRepositoryInterface;
use App\OrderTesting\Domain\SourceOrderReaderInterface;
use App\OrderTesting\Domain\StoreReaderInterface;
use App\OrderTesting\Domain\TestAttemptRepositoryInterface;
use App\Shared\Domain\Exception\NotFoundException;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

use function array_values;

/**
 * The demo orders one source order produced — where "View (N)" goes.
 *
 * A page rather than a modal. The store page's dialogs are fed by JavaScript that Order Testing shares
 * with the audio surface, and adding to it would mean editing `audio-store.js`, which this feature is
 * explicitly not allowed to touch. A page also has an address an operator can keep, which matters for
 * something a trainer will want to come back to.
 *
 * ## It is a list, not a comparison
 *
 * Each row links on to its own comparison. Several demo orders for one source order are several
 * separate pieces of work by possibly several people, and merging them into one view would make the
 * question "did this trainee get it right" unanswerable.
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
        private TestAttemptRepositoryInterface $attempts,
        private StoreReaderInterface $stores,
        private SourceOrderReaderInterface $sourceOrders,
        /*
         * Which of these rows the reader may see.
         *
         * Held, not consulted, by this surface: the whole point of a management list is that it is
         * everybody's. The practice surface overrides {@see visibleTo()}, and holding the port here
         * is what lets it do that without restating this constructor.
         */
        protected PracticeResultVisibility $visibility,
    ) {}

    public function __invoke(
        #[RouteArgument]
        int $sourceId,
        #[RouteArgument]
        int $sourceOrderId,
    ): ResponseInterface {
        $storeName = $this->stores->nameOf($sourceId);

        if ($storeName === null) {
            throw new NotFoundException('store_not_found', 'No store has that source id.');
        }

        // Narrowed before anything is read off it, so a row the reader may not see never reaches the
        // template — nor the attempt query below, nor the count the page prints.
        $orders = $this->visibleTo($this->demoOrders->forSourceOrder($sourceId, $sourceOrderId));

        // One query for every attribution on the page, rather than one per row.
        $attemptIds = [];
        foreach ($orders as $order) {
            if ($order->matchedAttemptId !== null) {
                $attemptIds[$order->matchedAttemptId] = $order->matchedAttemptId;
            }
        }

        return $this->viewRenderer
            ->withLayout($this->layoutPath())
            ->render(__DIR__ . '/template', [
                'routes' => $this->surfaceRoutes(),
                'sourceId' => $sourceId,
                'storeName' => $storeName,
                'sourceOrderId' => $sourceOrderId,
                'orders' => $orders,
                'attempts' => $this->attempts->byIds(array_values($attemptIds)),
                // Only to say whether the left-hand side of each comparison will have anything on it.
                // Resolved once here rather than once per row.
                'sourceOrderIsMirrored' => $this->sourceOrders->find($sourceId, $sourceOrderId) !== null,
            ]);
    }

    /**
     * Which of this source order's demo orders this surface shows.
     *
     * All of them here, Unmatched included: a trainer comparing two trainees' work needs both, and an
     * unattributed row is a thing somebody has to go and look at. The practice surface shows one
     * person their own.
     *
     * @param list<DemoOrder> $orders
     *
     * @return list<DemoOrder>
     */
    protected function visibleTo(array $orders): array
    {
        return $orders;
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
