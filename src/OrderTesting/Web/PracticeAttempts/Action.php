<?php

declare(strict_types=1);

namespace App\OrderTesting\Web\PracticeAttempts;

use App\OrderTesting\Application\Practice\PracticeHistoryBuilder;
use App\OrderTesting\Domain\DemoOrderRepositoryInterface;
use App\OrderTesting\Domain\InitiatorType;
use App\OrderTesting\Domain\SourceOrderReaderInterface;
use App\OrderTesting\Domain\StoreReaderInterface;
use App\OrderTesting\Domain\TestActorInterface;
use App\OrderTesting\Domain\TestAttemptRepositoryInterface;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Application\Time\AppTimeZone;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

use function is_string;

/**
 * Practice Attempts: who practised this source order, how many times, and how they did.
 *
 * The destination of the Demo Orders "View (N)" link. The flat listing it used to open is still
 * there, one click away under **All Demo Orders** — this page answers a different question. The
 * listing says what was imported; this says what somebody did.
 *
 * ## The agent surface subclasses this
 *
 * Exactly as {@see \App\OrderTesting\Web\DemoOrders\Action} is subclassed: the agent realm supplies
 * its own addresses, its own shell, and — the part that matters — its own actor filter. The page
 * body is identical because the difference between the realms is *which rows exist*, not how a card
 * is drawn.
 *
 * ## Scope is decided here, not in the template
 *
 * {@see actorFilter()} is pushed into the repository query. An agent's history never leaves the
 * database with somebody else's rows in it, so a template that rendered the list differently could
 * not leak them.
 */
readonly class Action
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private DemoOrderRepositoryInterface $demoOrders,
        private TestAttemptRepositoryInterface $attempts,
        private StoreReaderInterface $stores,
        private SourceOrderReaderInterface $sourceOrders,
        private PracticeHistoryBuilder $history,
        // Who is reading, for deciding whether this is a retry or a first attempt. Protected
        // so the agent surface can reuse it rather than taking a second copy of the same port.
        protected TestActorInterface $actor,
        // Timestamps are stored UTC and read in the business timezone, as every other page here does.
        private AppTimeZone $appTimeZone,
    ) {}

    public function __invoke(
        #[RouteArgument]
        int $sourceId,
        #[RouteArgument]
        int $sourceOrderId,
        ServerRequestInterface $request,
    ): ResponseInterface {
        $storeName = $this->stores->nameOf($sourceId);

        if ($storeName === null) {
            throw new NotFoundException('store_not_found', 'No store has that source id.');
        }

        [$actorType, $actorId] = $this->actorFilter();

        $attempts = $this->attempts->historyFor($sourceId, $sourceOrderId, $actorType, $actorId);

        /*
         * The demo orders a reader of this page may see.
         *
         * An agent gets only the ones attached to their own attempts — filtered here, against the
         * attempt ids the query above already narrowed, so a demo order belonging to somebody else's
         * attempt is never carried into the view at all. Administrators and operations users see
         * every one, including the ones with no owner.
         */
        $demoOrders = $this->demoOrders->forSourceOrder($sourceId, $sourceOrderId);
        $seesEverything = $actorType === null;

        if (!$seesEverything) {
            $mine = [];

            foreach ($attempts as $attempt) {
                $mine[$attempt->id] = true;
            }

            $visible = [];

            foreach ($demoOrders as $demo) {
                if ($demo->matchedAttemptId !== null && isset($mine[$demo->matchedAttemptId])) {
                    $visible[] = $demo;
                }
            }

            $demoOrders = $visible;
        }

        $groups = $this->history->build(
            $sourceId,
            $sourceOrderId,
            $attempts,
            $demoOrders,
            // Unmatched is a management concern: a demo order nobody can be shown to have made is
            // not an agent's to claim, so that realm is not offered it.
            $seesEverything,
        );

        /*
         * Narrowing to one practitioner, applied after grouping.
         *
         * Safe to do here, and only here, because this is a *view* filter over rows the reader is
         * already entitled to — the authorization narrowing happened in the query above. A chosen
         * key that matches nothing shows everything rather than erroring: a query string is not a
         * form, and a stale link should not be a dead end.
         */
        $chosen = $request->getQueryParams()['agent'] ?? null;
        $chosen = is_string($chosen) && $chosen !== '' ? $chosen : null;
        $shown = $groups;

        if ($chosen !== null) {
            $matched = [];

            foreach ($groups as $group) {
                if ($group->key() === $chosen) {
                    $matched[] = $group;
                }
            }

            if ($matched !== []) {
                $shown = $matched;
            } else {
                $chosen = null;
            }
        }

        return $this->viewRenderer
            ->withLayout($this->layoutPath())
            ->render(__DIR__ . '/template', [
                'routes' => $this->surfaceRoutes(),
                'appTimeZone' => $this->appTimeZone,
                'allGroups' => $groups,
                'chosenAgent' => $chosen,
                'sourceId' => $sourceId,
                'storeName' => $storeName,
                'sourceOrderId' => $sourceOrderId,
                'groups' => $shown,
                'canRetry' => $this->canRetry(),
                /*
                 * Whether this reader has finished this order before.
                 *
                 * "Retry" is a claim about the person reading, not about the page: an administrator
                 * looking at somebody else's completed history has not practised it themselves, and
                 * a button offering to *re*try would be wrong. Clicking Practice Order does not earn
                 * it either — only an imported, attributed demo order does.
                 *
                 * Counted over the reader's own attempts, so it is the same evidence the cards are
                 * built from.
                 */
                'readerHasPractised' => $this->readerHasCompleted($attempts, $demoOrders),
                // Only to say whether the left-hand side of each comparison will have anything on it.
                'sourceOrderIsMirrored' => $this->sourceOrders->find($sourceId, $sourceOrderId) !== null,
            ]);
    }

    /**
     * Has the reader themselves produced an imported demo order for this source order?
     *
     * Only an attempt of *theirs* that a demo order was attributed to counts. A pending attempt, an
     * expired one, an Unmatched demo order and somebody else's result all answer no.
     *
     * @param list<\App\OrderTesting\Domain\TestAttempt> $attempts
     * @param list<\App\OrderTesting\Domain\DemoOrder>   $demoOrders
     */
    private function readerHasCompleted(array $attempts, array $demoOrders): bool
    {
        [$realm, $id] = $this->readerIdentity();

        if ($realm === null || $id === null) {
            return false;
        }

        $mine = [];

        foreach ($attempts as $attempt) {
            if ($attempt->initiatedByType === $realm && $attempt->initiatedById === $id) {
                $mine[$attempt->id] = true;
            }
        }

        foreach ($demoOrders as $demo) {
            if ($demo->matchedAttemptId !== null && isset($mine[$demo->matchedAttemptId])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whose history this page shows.
     *
     * `[null, null]` means everybody, which is what Manage Orders and Practice Orders give their
     * administrators and operations users. The agent surface overrides it with that agent's own
     * realm and id.
     *
     * @return array{0: ?InitiatorType, 1: ?int}
     */
    protected function actorFilter(): array
    {
        return [null, null];
    }

    /**
     * Whether this surface may start a practice attempt at all.
     *
     * True for every surface whose route group mounts the Practice Order endpoint the button posts
     * to. Whether that button says **Retry** or **Start** is a separate question, answered per
     * reader by the action — see below.
     */
    protected function canRetry(): bool
    {
        return true;
    }

    /**
     * Who the reader is, for deciding whether they have practised this order before.
     *
     * Returns the actor filter when the surface shows one person's history, and `[null, null]` on
     * the management surfaces — where the page may be full of other people's results and the reader
     * has their own, separate, standing.
     *
     * @return array{0: ?InitiatorType, 1: ?int}
     */
    protected function readerIdentity(): array
    {
        return [$this->actor->initiatorType(), $this->actor->initiatorId()];
    }

    /**
     * The addresses this page builds its own links from.
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
            // This page's own address, for the filter's Clear link.
            'self' => 'order-testing.demo-orders',
            'allDemoOrders' => 'order-testing.demo-orders.all',
            'demoCompare' => 'order-testing.demo-compare',
            'demoUrl' => 'order-testing.demo-url',
        ];
    }

    /** The shell this page is drawn inside. The agent surface has its own. */
    protected function layoutPath(): string
    {
        return '@src/Web/Shared/Layout/Admin/layout.php';
    }
}
