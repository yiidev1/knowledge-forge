<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Job\Review;

use App\AudioToText\Domain\Exception\ReviewConflict;
use App\AudioToText\Domain\Exception\ReviewRejected;
use App\AudioToText\Web\AudioToTextRoute;
use App\Auth\Application\CurrentAdmin;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\FormData;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function json_encode;
use function str_contains;

use const JSON_HEX_TAG;
use const JSON_THROW_ON_ERROR;

/**
 * The shared half of every correction endpoint: who asked, which version they had, and what to say.
 *
 * Six actions differ only in the one service call they make, so everything around that call lives here
 * once. Chiefly the outcome handling — a refusal and a lost race are the two things an administrator
 * will actually encounter, and each needs to be reported in a way that leaves them able to continue.
 */
final readonly class ReviewRequest
{
    public function __construct(
        private CurrentAdmin $currentAdmin,
        private Redirect $redirect,
        private FlashMessages $flash,
        private ResponseFactoryInterface $responseFactory,
    ) {}

    public function adminId(): int
    {
        return $this->currentAdmin->get()->id();
    }

    /**
     * The `review_count` the page was rendered from.
     *
     * Absent or unparseable becomes -1 rather than 0, because 0 is a real version — the one every
     * never-corrected job has. A missing field must lose the race, not silently win it.
     */
    public function expectedVersion(ServerRequestInterface $request): int
    {
        $form = FormData::fromRequest($request);

        return $form->has('expected_review_count') ? (int) $form->string('expected_review_count') : -1;
    }

    public function form(ServerRequestInterface $request): FormData
    {
        return FormData::fromRequest($request);
    }

    /**
     * Run one correction and turn its outcome into a redirect back to the review page.
     *
     * Post/Redirect/Get throughout, as the chat editor does: the page that follows is re-read from the
     * database, so whatever the administrator sees next is current and the forms on it carry a fresh
     * version — including, and especially, after a conflict.
     *
     * ## One operation, two ways of reporting it
     *
     * The correction itself is identical either way — same service call, same validation, same
     * `reviewed_*` write, same audit row, same `review_count` guard. What differs is only how the
     * answer is delivered, because the modal on the store page cannot follow a redirect without
     * destroying itself:
     *
     *  - an ordinary submission gets the flash and the 303 it always got, byte for byte;
     *  - a request that asked for JSON gets the same sentence in a payload it can render in place.
     *
     * The branch is on what the *client* asked for, never on anything about the operation, so no
     * correction can behave differently depending on how it was submitted.
     *
     * @param callable(): void $operation
     */
    public function apply(
        string $publicId,
        string $success,
        callable $operation,
        ?ServerRequestInterface $request = null,
    ): ResponseInterface {
        $message = $success;
        $ok = true;
        $status = 200;

        try {
            $operation();
            $this->flash->success($success);
        } catch (ReviewConflict) {
            // Deliberately not the exception's own message: what matters to the person who lost the
            // race is that their change did not happen and that the page below is now the current one.
            $message = 'Somebody else corrected this conversation while you had it open. Your change was not '
                . 'applied. The conversation below is the current version — please make your change again.';
            $ok = false;
            // 409, because the request was well formed and lost a race — the client should re-read and
            // try again, which is exactly what the modal does.
            $status = 409;
            $this->flash->error($message);
        } catch (ReviewRejected $e) {
            // These messages were written for administrators in the domain layer, so they are shown
            // as-is rather than translated into something vaguer here.
            $message = $e->getMessage();
            $ok = false;
            // 422: the correction itself was refused, not the request.
            $status = 422;
            $this->flash->error($message);
        }

        if ($request !== null && $this->wantsJson($request)) {
            // The flash was set above and would otherwise sit in the session waiting to surprise the
            // next full page load with a stale message, so it is consumed here and handed back instead.
            $this->flash->consume();

            return $this->json(['success' => $ok, 'message' => $message], $status);
        }

        return $this->redirect->afterPost(AudioToTextRoute::JOB_REVIEW, ['publicId' => $publicId]);
    }

    /**
     * Whether this caller wants an answer rather than a new page.
     *
     * Both signals have to be explicit: a browser sends a wildcard `Accept` header for an ordinary
     * form post, so testing `Accept` alone would flip every existing submission to JSON.
     */
    public function wantsJson(ServerRequestInterface $request): bool
    {
        if ($request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest') {
            return true;
        }

        return str_contains($request->getHeaderLine('Accept'), 'application/json');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload, int $status): ResponseInterface
    {
        $response = $this->responseFactory
            ->createResponse($status)
            ->withHeader('Content-Type', 'application/json; charset=UTF-8')
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('X-Content-Type-Options', 'nosniff');

        // JSON_HEX_TAG because a refusal message can quote transcript text a person typed.
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_HEX_TAG));

        return $response;
    }
}
