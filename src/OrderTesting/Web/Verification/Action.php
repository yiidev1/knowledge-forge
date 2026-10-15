<?php

declare(strict_types=1);

namespace App\OrderTesting\Web\Verification;

use App\OrderTesting\Application\OrderVerificationService;
use App\OrderTesting\Domain\OrderVerification;
use App\OrderTesting\Domain\TestActorInterface;
use App\OrderTesting\Domain\VerificationAction;
use App\OrderTesting\Domain\VerificationConflict;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\FormData;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

use function implode;
use function json_encode;
use function preg_match;
use function str_contains;
use function strtolower;
use function strtoupper;

use const JSON_HEX_TAG;
use const JSON_THROW_ON_ERROR;

/**
 * Marking one source order as checked over, or taking that back.
 *
 * ## One endpoint, two answers
 *
 * The page's script posts here and repaints in place; a browser with no script posts the same form and
 * is redirected back with a flash. The shape of the answer is chosen from headers, never from a field
 * in the body — the same rule {@see \App\OrderTesting\Web\DemoUrl\Action} applies, and for the same
 * reason: an ordinary form post sends a wildcard `Accept`, so testing `Accept` alone would hand the
 * no-script path a JSON document it cannot do anything with.
 *
 * ## What this endpoint will not take from the caller
 *
 * **Who they are.** The actor comes from {@see TestActorInterface}, which the gate populated from the
 * session it verified. A posted actor type or id is read by nothing here.
 *
 * **Which store.** It is in the URL, and the gate scoped the request to it before this action ran.
 *
 * **Whether the order is theirs.** The service checks the `(store, order)` pair against the orders
 * mirror, so an order id belonging to another store is refused even though the caller may legitimately
 * reach this store.
 *
 * ## Refusals, and the one that is not a refusal
 *
 * A stale version is answered **409 with the current state**, not a bare error: the screen that sent it
 * was showing something that is no longer true, and the only useful reply is what is true now. A
 * repeat of a change that has already happened is **not** a conflict — the click said something about
 * the order, the order already says it, and the event log records that this person said so too.
 */
final readonly class Action
{
    public function __construct(
        private OrderVerificationService $verifications,
        private TestActorInterface $actor,
        private FlashMessages $flash,
        private Redirect $redirect,
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
    ) {}

    public function __invoke(
        ServerRequestInterface $request,
        #[RouteArgument]
        int $sourceId,
        #[RouteArgument]
        int $sourceOrderId,
    ): ResponseInterface {
        $form = FormData::fromRequest($request);
        $wantsJson = $this->wantsJson($request);

        // `decision` rather than `action`: see the template, where a field of that name shadowed
        // the form's own URL in the browser.
        $action = VerificationAction::tryFrom(strtoupper($form->string('decision')));

        if ($action === null) {
            return $this->refuse(
                $wantsJson,
                $sourceId,
                'That is not something this page can do to an order.',
                Status::UNPROCESSABLE_ENTITY,
            );
        }

        try {
            $state = $this->verifications->apply(
                $sourceId,
                $sourceOrderId,
                $action,
                $this->actor,
                $this->expectedVersion($form->string('version')),
            );
        } catch (VerificationConflict $conflict) {
            return $this->conflict($wantsJson, $sourceId, $conflict);
        } catch (ValidationException $e) {
            return $this->refuse(
                $wantsJson,
                $sourceId,
                implode(' ', $e->errors()),
                Status::UNPROCESSABLE_ENTITY,
            );
        }

        $message = $state->isVerified
            ? 'Order verified successfully.'
            : 'Order verification removed successfully.';

        if ($wantsJson) {
            return $this->json($this->state($state) + ['message' => $message], Status::OK);
        }

        $this->flash->success($message);

        return $this->redirect->afterPost('order-testing.store', ['sourceId' => $sourceId]);
    }

    /**
     * The version the writer was looking at, or null when they sent none.
     *
     * Null waives the check deliberately: a form that was rendered before this feature existed, or a
     * caller that does not track versions, still gets to act — it is the *stale* version that is
     * dangerous, not the absent one.
     */
    private function expectedVersion(string $raw): ?int
    {
        return preg_match('/\A[0-9]{1,9}\z/', $raw) === 1 ? (int) $raw : null;
    }

    /** @return array<string, bool|int|string|null> */
    private function state(OrderVerification $state): array
    {
        return [
            'verified' => $state->isVerified,
            'version' => $state->version,
            'label' => $state->isVerified ? 'Verified' : 'Not verified',
        ];
    }

    private function conflict(bool $wantsJson, int $sourceId, VerificationConflict $conflict): ResponseInterface
    {
        $message = $conflict->getMessage() . ' The current state is shown below.';

        if ($wantsJson) {
            return $this->json(
                $this->state($conflict->current) + ['error' => $message],
                Status::CONFLICT,
            );
        }

        $this->flash->error($message);

        return $this->redirect->afterPost('order-testing.store', ['sourceId' => $sourceId]);
    }

    private function refuse(bool $wantsJson, int $sourceId, string $message, int $status): ResponseInterface
    {
        if ($wantsJson) {
            return $this->json(['error' => $message], $status);
        }

        $this->flash->error($message);

        return $this->redirect->afterPost('order-testing.store', ['sourceId' => $sourceId]);
    }

    /** @param array<string, bool|int|string|null> $payload */
    private function json(array $payload, int $status): ResponseInterface
    {
        return $this->responses
            ->createResponse($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            // JSON_HEX_TAG for the same reason every other JSON answer here uses it: this body must
            // stay inert if it is ever read into a page.
            ->withBody($this->streams->createStream(
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_HEX_TAG),
            ));
    }

    private function wantsJson(ServerRequestInterface $request): bool
    {
        if ($request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest') {
            return true;
        }

        return str_contains(strtolower($request->getHeaderLine('Accept')), 'application/json');
    }
}
