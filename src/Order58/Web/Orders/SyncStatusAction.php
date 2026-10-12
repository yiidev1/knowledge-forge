<?php

declare(strict_types=1);

namespace App\Order58\Web\Orders;

use App\Order58\Domain\Items\ItemSyncBatchRepositoryInterface;
use App\Shared\Domain\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

use function is_string;
use function json_encode;
use function preg_match;

use const JSON_THROW_ON_ERROR;

/**
 * What one synchronisation operation has got to, as JSON, for the panel to poll.
 *
 * ## Read-only, and derived on every call
 *
 * Nothing here starts, resumes or settles anything. Every figure is recomputed from the batch's
 * enrolment rows, so a page that polls during a restart sees the truth as soon as the rows change —
 * there is no cached total to go stale and no counter to correct.
 *
 * ## It cannot report a finished operation that is not finished
 *
 * The verdict comes from {@see \App\Order58\Domain\Items\BatchOutcome::decide()}, which reads
 * `COMPLETED` only when no enrolment is still unsettled. A batch with pending work polls as running
 * however long it has been quiet; prolonged silence is reported as **stalled**, which is a statement
 * about the importer not having run — most often because its systemd units are not installed — and
 * never a verdict about the data.
 */
final readonly class SyncStatusAction
{
    public function __construct(
        private ItemSyncBatchRepositoryInterface $batches,
        private ClockInterface $clock,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        private int $stalledAfterSeconds,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $batchId = $this->positiveInt($request->getQueryParams()['batch'] ?? null);

        if ($batchId === null) {
            return $this->json(['success' => false, 'reason' => 'no_batch'], 400);
        }

        $progress = $this->batches->progressFor($batchId, $this->clock->now(), $this->stalledAfterSeconds);

        if ($progress === null) {
            return $this->json(['success' => false, 'reason' => 'unknown_batch'], 404);
        }

        return $this->json([
            'success' => true,
            'batch' => $progress->batchId,
            'trigger' => $progress->triggerSource,
            'outcome' => $progress->outcome->value,
            'outcomeLabel' => $progress->outcome->label(),
            'modifier' => $progress->outcome->modifier(),
            'finished' => $progress->outcome->isFinished(),
            'state' => $progress->state(),
            'percent' => $progress->percent(),
            'ordersReceived' => $progress->ordersReceived,
            'ordersSaved' => $progress->ordersSaved,
            'eligible' => $progress->enrolled,
            'done' => $progress->done,
            'remaining' => $progress->remaining(),
            'failed' => $progress->failed,
            'skipped' => $progress->skipped,
            'truncated' => $progress->ordersTruncated,
            'ordersError' => $progress->ordersError,
            'stalled' => $progress->stalled,
            'startedAt' => $progress->startedAt,
            'finishedAt' => $progress->finishedAt,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): ResponseInterface
    {
        return $this->responseFactory
            ->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            // Polled every few seconds; a cached answer would show a finished operation as running.
            ->withHeader('Cache-Control', 'no-store, private')
            ->withBody($this->streamFactory->createStream(json_encode($payload, JSON_THROW_ON_ERROR)));
    }

    /** Digits only and bounded; anything else is refused rather than cast into a different number. */
    private function positiveInt(mixed $raw): ?int
    {
        return is_string($raw) && preg_match('/^[1-9][0-9]{0,18}$/', $raw) === 1 ? (int) $raw : null;
    }
}
