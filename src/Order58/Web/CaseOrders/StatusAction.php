<?php

declare(strict_types=1);

namespace App\Order58\Web\CaseOrders;

use App\Order58\Application\CaseOrders\CaseOrderSyncCoordinator;
use App\Order58\Domain\CaseOrders\CaseOrderSyncRun;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

use function is_string;
use function json_encode;
use function preg_match;

use const JSON_THROW_ON_ERROR;

/**
 * What today's case order synchronisation has got to (GET …/case-orders/status).
 *
 * ## Read-only, and it starts nothing
 *
 * The panel polls this while the POST that does the work is still in flight. That is the whole reason
 * it exists: the import runs inside its own request — the operator gets real counts rather than a job
 * id, the same arrangement the Orders sync already uses — and a request doing a provider call plus two
 * hundred imports has no way to report on itself as it goes. The run row does, because every stage is
 * written before the next begins.
 *
 * It also means an operator whose browser gave up on the POST still learns what happened: the row
 * outlives the request.
 *
 * ## What it will not return
 *
 * The staged payload, the token, the URL, or any provider text. Stages, counts, the HTTP status and
 * this application's own sanitized sentence — enough to draw the bar and say what happened.
 */
final readonly class StatusAction
{
    public function __construct(
        private CaseOrderSyncCoordinator $coordinator,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /*
         * The run that was asked about, or the one actually running. **Never "the most recent".**
         *
         * It used to fall back to `latest()`, which meant a page with no synchronisation in flight
         * answered with the last finished one — so the confirmation dialog, which polls this, showed
         * a previous run's Completed badge and counts as though they were the operation about to be
         * started. A finished run belongs to the history table, not to a progress panel.
         *
         * Read-only either way: polling this must never decide anything, or a browser left open
         * would drive the provider.
         */
        $requested = $this->runId($request->getQueryParams()['run'] ?? null);

        $run = $requested === null
            ? $this->coordinator->active()
            : $this->coordinator->find($requested);

        if ($run === null) {
            return $this->json([
                'success' => true,
                'running' => false,
                'hasRun' => false,
                'latest' => null,
            ]);
        }

        return $this->json([
            'success' => true,
            'running' => !$run->status->isFinished(),
            'hasRun' => true,
            'run' => $this->describe($run),
            'latest' => $this->describe($run),
        ]);
    }

    /** Digits only and bounded, or null; an unreadable id asks about no run rather than another. */
    private function runId(mixed $raw): ?int
    {
        return is_string($raw) && preg_match('/^[1-9][0-9]{0,18}$/', $raw) === 1 ? (int) $raw : null;
    }

    /**
     * @see CaseOrderRunView for the shape itself, which the synchronisation POST also answers with.
     *
     * @return array<string, mixed>|null
     */
    private function describe(?CaseOrderSyncRun $run): ?array
    {
        return CaseOrderRunView::describe($run);
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): ResponseInterface
    {
        return $this->responseFactory
            ->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            // Polled every few seconds; a cached answer would show a finished run as still running.
            ->withHeader('Cache-Control', 'no-store, private')
            ->withBody($this->streamFactory->createStream(json_encode($payload, JSON_THROW_ON_ERROR)));
    }
}
