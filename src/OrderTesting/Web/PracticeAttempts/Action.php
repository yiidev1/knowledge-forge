<?php

declare(strict_types=1);

namespace App\OrderTesting\Web\PracticeAttempts;

use App\OrderTesting\Application\Practice\PractitionerHistory;
use App\OrderTesting\Application\Practice\PractitionerScorer;
use App\OrderTesting\Domain\InitiatorType;
use App\OrderTesting\Domain\Practice\PracticeResultsQuery;
use App\OrderTesting\Domain\Practice\PracticeResultsReaderInterface;
use App\OrderTesting\Domain\Practice\PracticeTopicReaderInterface;
use App\OrderTesting\Domain\SourceOrderReaderInterface;
use App\OrderTesting\Domain\StoreReaderInterface;
use App\OrderTesting\Domain\TestActorInterface;
use App\Shared\Application\Time\AppTimeZone;
use App\Shared\Domain\Exception\NotFoundException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

use function ceil;
use function is_numeric;
use function max;
use function min;

/**
 * Practice Results: who practised one source order, and how they did.
 *
 * ## What this replaced, and why
 *
 * The page used to draw one card per try, grouped under one heading per practitioner, and it built
 * every one of them on every request: all the attempts, all the demo orders with their whole
 * documents, and a full comparison scored for each. At the four results this store had, that read
 * fine. At the hundred practitioners with three or four results each that the client described, it
 * is four hundred documents parsed and compared to draw a page nobody can scroll through anyway.
 *
 * So the page is a table now, and the table is paged. What it costs is set by the page size rather
 * than by how many people have practised:
 *
 * - **Who practised** — one indexed aggregate, no documents at all.
 * - **Their scores** — only for the rows on screen, under a budget. See {@see PractitionerScorer}.
 * - **Their individual tries** — only when somebody opens that practitioner, in the drawer.
 *
 * ## The agent surface subclasses this and shows one person their own results
 *
 * Exactly as before: {@see actorFilter()} is the authorization boundary and it is pushed into the
 * query, so an agent's history is fetched narrowed rather than filtered afterwards. The difference
 * is that an agent needs no table of practitioners — there is one — so that surface renders its own
 * template over the same services.
 */
readonly class Action
{
    /** Practitioners per page. Twenty-five is what the rest of this application pages by. */
    protected const PER_PAGE = 25;

    /**
     * Unmatched results listed at once.
     *
     * Bounded like everything else here, and bounded low: this section is a prompt to go and look
     * into something, not a report. Its count is exact regardless.
     */
    protected const UNMATCHED_SHOWN = 25;

    public function __construct(
        private WebViewRenderer $viewRenderer,
        private PracticeResultsReaderInterface $results,
        private PractitionerScorer $scorer,
        /** One practitioner's own tries, for the drawer and for an agent's own page. */
        private PractitionerHistory $history,
        private StoreReaderInterface $stores,
        private SourceOrderReaderInterface $sourceOrders,
        /** The order's current topic, shown as context. Never a filter — see the template. */
        private PracticeTopicReaderInterface $topics,
        // Who is reading, for deciding whether this is a retry or a first attempt. Protected so the
        // agent surface can reuse it rather than taking a second copy of the same port.
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

        $params = $request->getQueryParams();
        $query = PracticeResultsQuery::fromParams($params);

        [$realm, $actorId] = $this->actorFilter();

        /*
         * An agent's page is their own results, so there is no table of practitioners to page.
         *
         * Decided here rather than in the template, because it decides which queries run at all: the
         * aggregate below is a management question, and asking it on a surface that may not see the
         * answer would be fetching data to then not show it.
         */
        if ($realm !== null && $actorId !== null) {
            return $this->renderOwn($sourceId, $sourceOrderId, $storeName, $realm, $actorId);
        }

        $total = $this->results->countPractitioners($sourceId, $sourceOrderId, $query);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = $this->page($params, $pages);

        $unmatched = $this->results->unmatched($sourceId, $sourceOrderId, self::UNMATCHED_SHOWN, 0);

        $practitioners = $this->results->practitioners(
            $sourceId,
            $sourceOrderId,
            $query,
            self::PER_PAGE,
            ($page - 1) * self::PER_PAGE,
        );

        return $this->viewRenderer
            ->withLayout($this->layoutPath())
            ->render(__DIR__ . '/template', [
                'routes' => $this->surfaceRoutes(),
                'appTimeZone' => $this->appTimeZone,
                'sourceId' => $sourceId,
                'storeName' => $storeName,
                'sourceOrderId' => $sourceOrderId,
                'query' => $query,
                'practitioners' => $practitioners,
                // Scored for these rows only, and only as far as the budget reaches.
                'scores' => $this->scorer->forPage($sourceId, $sourceOrderId, $practitioners),
                'page' => $page,
                'pages' => $pages,
                'perPage' => self::PER_PAGE,
                'totalPractitioners' => $total,
                'totalResults' => $this->results->resultCount($sourceId, $sourceOrderId),
                'unmatchedCount' => $this->results->unmatchedCount($sourceId, $sourceOrderId),
                'unmatched' => $unmatched,
                // The same canonical scorer the attributed rows use. An unattributed result is worth
                // what it is worth; what it has no claim to is a practitioner.
                'unmatchedScores' => $this->history->scoreEach($sourceId, $sourceOrderId, $unmatched),
                /*
                 * The source order's topic, as a fact about the order.
                 *
                 * Not a filter, and deliberately so. A topic is assigned to a source order —
                 * `practice_order_topics` is unique on `(store, order)` — so every result on this
                 * page carries the same one, and a dropdown offering it would exclude nothing. It is
                 * shown here because a reviewer wants to know what the practice was about.
                 */
                'topic' => $this->topics->forOrder($sourceId, $sourceOrderId),
                'canRetry' => $this->canRetry(),
                'readerHasPractised' => $this->readerHasPractised($sourceId, $sourceOrderId),
                'sourceOrderIsMirrored' => $this->sourceOrders->find($sourceId, $sourceOrderId) !== null,
            ]);
    }

    /**
     * One practitioner's own results, for a surface that shows nobody else's.
     *
     * The same reader and the same scorer, asked for one person. No practitioner aggregate runs, no
     * unmatched section is fetched, and no global counts reach the template — an agent is not shown
     * how many other people practised, because that is management information about colleagues.
     */
    private function renderOwn(
        int $sourceId,
        int $sourceOrderId,
        string $storeName,
        InitiatorType $realm,
        int $actorId,
    ): ResponseInterface {
        $total = $this->results->countResultsFor($sourceId, $sourceOrderId, $realm, $actorId);

        return $this->viewRenderer
            ->withLayout($this->layoutPath())
            ->render(__DIR__ . '/own', [
                'routes' => $this->surfaceRoutes(),
                'appTimeZone' => $this->appTimeZone,
                'sourceId' => $sourceId,
                'storeName' => $storeName,
                'sourceOrderId' => $sourceOrderId,
                'tries' => $this->history->forPractitioner($sourceId, $sourceOrderId, $realm, $actorId),
                'totalTries' => $total,
                'topic' => $this->topics->forOrder($sourceId, $sourceOrderId),
                'canRetry' => $this->canRetry(),
                // An agent has practised when THEY have an imported result, which is exactly what
                // the count above is. Never another practitioner's.
                'readerHasPractised' => $total > 0,
                'sourceOrderIsMirrored' => $this->sourceOrders->find($sourceId, $sourceOrderId) !== null,
            ]);
    }

    /**
     * Which page is being asked for, clamped into the range that exists.
     *
     * A stale link to page nine of a list that now has two is answered with page two rather than an
     * empty table: the filter narrowed, and showing nothing would read as "no results" rather than
     * "you are past the end".
     *
     * @param array<array-key, mixed> $params
     */
    private function page(array $params, int $pages): int
    {
        $raw = $params[PracticeResultsQuery::PAGE] ?? null;
        $page = is_numeric($raw) ? (int) $raw : 1;

        return min(max(1, $page), $pages);
    }

    /**
     * Has the reader themselves produced an imported result for this source order?
     *
     * "Retry" is a claim about the person reading, not about the page: an administrator looking at
     * somebody else's completed history has not practised it, and a button offering to *re*try would
     * be wrong. Clicking Practice Order does not earn it either — only an imported, attributed
     * result does, which is what this counts.
     */
    protected function readerHasPractised(int $sourceId, int $sourceOrderId): bool
    {
        $realm = $this->actor->initiatorType();

        return $this->results->countResultsFor(
            $sourceId,
            $sourceOrderId,
            $realm,
            $this->actor->initiatorId(),
        ) > 0;
    }

    /**
     * Whose results this page shows.
     *
     * `[null, null]` means everybody's, which is what the management surfaces get. The agent surface
     * overrides it with that agent's own realm and id, and the override is an authorization
     * boundary: it reaches the query, so somebody else's results are never fetched at all.
     *
     * @return array{0: ?InitiatorType, 1: ?int}
     */
    protected function actorFilter(): array
    {
        return [null, null];
    }

    /** Whether this surface may start a practice attempt at all. */
    protected function canRetry(): bool
    {
        return true;
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
            'self' => 'order-testing.demo-orders',
            'tries' => 'order-testing.demo-orders.tries',
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
