<?php

declare(strict_types=1);

namespace App\OrderTesting\Web\DemoUrl;

use App\Auth\Application\CurrentAdmin;
use App\OrderTesting\Application\TestAttemptService;
use App\OrderTesting\Domain\InitiatorType;
use App\OrderTesting\Domain\SourceOrderReaderInterface;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

use function is_array;
use function is_string;
use function json_encode;
use function preg_match;
use function str_contains;
use function strtolower;

use const JSON_HEX_TAG;
use const JSON_THROW_ON_ERROR;

/**
 * Clicking Demo URL: record who is testing, then hand back the Order58 address.
 *
 * ## Why this does not redirect
 *
 * It used to. The cell was a form with `target="_blank"` that posted here and got a 303 to Order58,
 * and in Chrome the new tab opened and then sat on `about:blank`.
 *
 * The cause is `form-action 'self'` in {@see \App\Shared\Web\Middleware\SecurityHeadersMiddleware}.
 * **Chrome and Safari enforce `form-action` across redirects**, so a form submission that ends up at
 * `https://…order58.com/…` is blocked even though the POST itself was same-origin. Ctrl-clicking
 * appeared to work because a link navigation is not a form submission and `form-action` never applies
 * to one.
 *
 * Three ways out were available. Adding the Order58 domains to `form-action` weakens the policy for
 * every form in the application, and the hosts come from a mirrored column rather than a fixed list,
 * so they cannot be enumerated honestly. Dropping back to a plain link loses the attempt, which is the
 * whole point of the click. So the click now opens a tab itself and navigates it — a script-initiated
 * navigation is not a form submission either, and no directive in the policy restricts it.
 *
 * ## It answers two callers
 *
 * `Accept: application/json` gets `{"url": …}` and the script points its already-open tab at it.
 * Anything else — JavaScript off, a script that failed to load — gets a small same-origin page with
 * the link on it. That page renders in the new tab the form's `target="_blank"` opened, so the no-JS
 * path still records the attempt, still keeps the Order Testing page in place, and still ends one
 * ordinary click away from Order58.
 *
 * ## The destination is never request data
 *
 * The store and the order come from the route; the host and the phone come from the mirrored tables;
 * the URL is built by {@see \App\Shared\Order58\DemoOrderUrl}, which refuses any host outside an
 * allow-list. Nothing the browser sends reaches it. A `url` or `host` field in the POST body is read
 * by nothing at all — without that, this endpoint would be an open redirect handing a customer's
 * phone number to whoever chose the host.
 */
final readonly class Action
{
    public function __construct(
        private TestAttemptService $attempts,
        private SourceOrderReaderInterface $sourceOrders,
        private CurrentAdmin $currentAdmin,
        private FlashMessages $flash,
        private Redirect $redirect,
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
        private WebViewRenderer $viewRenderer,
    ) {}

    public function __invoke(
        #[RouteArgument]
        int $sourceId,
        ServerRequestInterface $request,
    ): ResponseInterface {
        $body = $request->getParsedBody();
        $orderId = $this->orderId(is_array($body) ? ($body['source_order_id'] ?? null) : null);
        $wantsJson = $this->wantsJson($request);

        if ($orderId === null) {
            return $this->refuse($wantsJson, $sourceId, 'That order number is not one this page can open a demo for.', Status::UNPROCESSABLE_ENTITY);
        }

        // Resolved BEFORE anything is written: an order that cannot produce a link is not a test
        // somebody is about to start, and recording an attempt for it would leave a row that can never
        // be matched and that holds the slot until it expires.
        $link = $this->sourceOrders->demoLinkFor($sourceId, $orderId);

        if (!$link->isReady()) {
            return $this->refuse($wantsJson, $sourceId, 'No demo link for this order: ' . $link->status->message() . '.', Status::UNPROCESSABLE_ENTITY);
        }

        $outcome = $this->attempts->start(
            $sourceId,
            $orderId,
            // The realm is fixed here, not taken from the request. A client-supplied actor type or id
            // would let anyone attribute their work to somebody else — the identity comes from the
            // authenticated session and nowhere else.
            InitiatorType::Admin,
            $this->currentAdmin->get()->id(),
        );

        if (!$outcome->isAllowed()) {
            // 409: the request was well formed and was refused because somebody else holds the slot.
            // The script closes its blank tab on any non-2xx, so a conflict never leaves one open.
            return $this->refuse($wantsJson, $sourceId, (string) $outcome->refusal, Status::CONFLICT);
        }

        $url = (string) $link->url;

        if ($wantsJson) {
            return $this->json(['url' => $url], Status::OK);
        }

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/continue', [
                'sourceId' => $sourceId,
                'sourceOrderId' => $orderId,
                'url' => $url,
            ]);
    }

    /**
     * Whether the caller is the page script rather than a browser following a form.
     *
     * Read from headers rather than from a field in the body: this decides only the response shape,
     * but keeping every decision out of the body means there is nothing in it this endpoint reads
     * except the order number.
     */
    private function wantsJson(ServerRequestInterface $request): bool
    {
        if ($request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest') {
            return true;
        }

        // Both signals have to be EXPLICIT. A browser sends a wildcard `Accept` for an ordinary form
        // post, so testing Accept alone would flip the no-JavaScript path to JSON and leave it with
        // nothing to click. `ReviewRequest` in the audio module already applies the same rule for the
        // same reason — named in prose rather than linked, because this module may not name that one
        // and `OrderTestingBoundariesTest` is what caught the first attempt at writing this comment.
        return str_contains(strtolower($request->getHeaderLine('Accept')), 'application/json');
    }

    private function refuse(bool $wantsJson, int $sourceId, string $message, int $status): ResponseInterface
    {
        if ($wantsJson) {
            return $this->json(['error' => $message], $status);
        }

        $this->flash->error($message);

        // Same-origin, so `form-action 'self'` permits this one.
        return $this->redirect->afterPost('order-testing.store', ['sourceId' => $sourceId]);
    }

    /**
     * @param array<string, string> $payload
     */
    private function json(array $payload, int $status): ResponseInterface
    {
        return $this->responses
            ->createResponse($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            // `nosniff` is global, so the type above must be exact; this one keeps a conflict answer
            // from being served again from a cache after the slot is released.
            ->withHeader('Cache-Control', 'no-store')
            // JSON_HEX_TAG, like every other JSON answer here: a refusal message quotes an operator's
            // name, and this body must stay inert if it is ever read into a page.
            ->withBody($this->streams->createStream(
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_HEX_TAG),
            ));
    }

    /**
     * The posted order number, or null.
     *
     * Digits only and bounded: it is used as an integer against an indexed column, and anything else is
     * refused rather than cast into a number that silently means something different.
     */
    private function orderId(mixed $value): ?int
    {
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,18}$/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }
}
