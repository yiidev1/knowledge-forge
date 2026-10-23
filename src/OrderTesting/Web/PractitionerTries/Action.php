<?php

declare(strict_types=1);

namespace App\OrderTesting\Web\PractitionerTries;

use App\OrderTesting\Application\Practice\PractitionerHistory;
use App\OrderTesting\Domain\InitiatorType;
use App\OrderTesting\Domain\Practice\PracticeResultsReaderInterface;
use App\OrderTesting\Domain\StoreReaderInterface;
use App\OrderTesting\Domain\TestActorInterface;
use App\Shared\Application\Time\AppTimeZone;
use App\Shared\Domain\Exception\NotFoundException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

use function is_numeric;
use function max;

/**
 * One practitioner's tries — what the View Tries drawer fills itself from.
 *
 * ## Why an endpoint of its own
 *
 * The table must not fetch every practitioner's history to draw twenty-five rows. So it fetches
 * none of them, and this answers for the one somebody opened. A hundred practitioners is a hundred
 * rows and zero comparisons until a reader asks about somebody.
 *
 * It is a real page as well as a fragment: the row's link points here, so with no script the drawer
 * falls back to a page showing exactly the same thing. One response, two presentations — rather than a
 * script-only feature with a dead link behind it.
 *
 * ## The realm is in the path, and it is not decoration
 *
 * A practitioner is `(realm, id)`. `admin_users.id = 1` and Order58's `admin_id = 1` are different
 * people in this installation, so an address carrying only the number would be ambiguous — and the
 * ambiguity would resolve to whichever row the query happened to find.
 *
 * ## An agent may only ask about themselves
 *
 * {@see admits()} is the check, and it is the whole of the authorization: the agent surface mounts
 * this endpoint too, so without it an agent could read a colleague's results by editing the address.
 * It compares realm **and** id against the signed-in actor, and answers a refusal as `not found` —
 * a reader who may not see somebody must not learn from the answer that they exist.
 */
readonly class Action
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private PracticeResultsReaderInterface $results,
        private PractitionerHistory $history,
        private StoreReaderInterface $stores,
        protected TestActorInterface $actor,
        private AppTimeZone $appTimeZone,
    ) {}

    public function __invoke(
        #[RouteArgument]
        int $sourceId,
        #[RouteArgument]
        int $sourceOrderId,
        #[RouteArgument]
        string $realm,
        #[RouteArgument]
        int $actorId,
        ServerRequestInterface $request,
    ): ResponseInterface {
        $storeName = $this->stores->nameOf($sourceId);

        if ($storeName === null) {
            throw new NotFoundException('store_not_found', 'No store has that source id.');
        }

        $initiator = InitiatorType::tryFrom($realm);

        if ($initiator === null || !$this->admits($initiator, $actorId)) {
            // The same words either way: a realm this application does not know, and a practitioner
            // this reader may not see, are both answered as a thing that is not there.
            throw new NotFoundException('practitioner_not_found', 'No such practitioner for this order.');
        }

        $params = $request->getQueryParams();
        $page = is_numeric($params['page'] ?? null) ? max(1, (int) $params['page']) : 1;

        $total = $this->results->countResultsFor($sourceId, $sourceOrderId, $initiator, $actorId);
        $tries = $this->history->forPractitioner($sourceId, $sourceOrderId, $initiator, $actorId, $page);

        return $this->viewRenderer
            ->withLayout($this->layoutPath())
            ->render(__DIR__ . '/template', [
                'routes' => $this->surfaceRoutes(),
                'appTimeZone' => $this->appTimeZone,
                'sourceId' => $sourceId,
                'storeName' => $storeName,
                'sourceOrderId' => $sourceOrderId,
                'realm' => $initiator,
                'actorId' => $actorId,
                'tries' => $tries,
                'totalTries' => $total,
                'page' => $page,
                'perPage' => PractitionerHistory::PER_PAGE,
            ]);
    }

    /**
     * Whether this reader may see this practitioner's results.
     *
     * True for the management surfaces, which exist to review everybody. The agent surface overrides
     * it to themselves alone.
     */
    protected function admits(InitiatorType $realm, int $actorId): bool
    {
        return true;
    }

    /** @return array<string, string> */
    protected function surfaceRoutes(): array
    {
        return [
            'page' => 'order-testing',
            'pageLabel' => 'Manage Orders',
            'store' => 'order-testing.store',
            'self' => 'order-testing.demo-orders.tries',
            'demoOrders' => 'order-testing.demo-orders',
            'demoCompare' => 'order-testing.demo-compare',
        ];
    }

    protected function layoutPath(): string
    {
        return '@src/Web/Shared/Layout/Admin/layout.php';
    }
}
