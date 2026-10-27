<?php

declare(strict_types=1);

namespace App\Order58\Application\CaseOrders;

use App\Order58\Client\CaseOrders\CaseOrderClientInterface;
use App\Order58\Client\CaseOrders\CaseOrderFetchFailed;
use App\Order58\Client\CaseOrders\CaseOrderSettings;
use App\Order58\Domain\CaseOrders\CaseOrderSyncRun;
use App\Order58\Domain\CaseOrders\CaseOrderSyncRunRepositoryInterface;
use App\Shared\Domain\Clock\ClockInterface;
use DateTimeImmutable;
use JsonException;
use Psr\Log\LoggerInterface;
use Throwable;

use function count;
use function implode;
use function is_array;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * One day's case order synchronisation, from the button to the imported rows.
 *
 * ## The order of the three steps is the design
 *
 * ```
 * 1. check the configuration    before anything is claimed, so a typo costs a message
 * 2. claim the day              an INSERT against UNIQUE(business_day) — this IS the rate limit
 * 3. fetch, stage, import       in that order, each recorded before the next begins
 * ```
 *
 * Step 1 comes first because a request costs a provider call: discovering a missing token
 * after claiming would burn the day on a request that was never made. Step 2 comes before step 3
 * because the claim is what stops a second tab, a second operator or the scheduler making a second
 * request — it is a database constraint, not a check, so there is no window between deciding and
 * acting.
 *
 * ## Staging is what makes a failure survivable
 *
 * The sanitized response is written to the run before anything is parsed. So a failure while importing
 * — a malformed order, a database error, a timeout — leaves the day's data in hand, and the local half
 * can be retried with {@see resume()} without asking the provider again. Without that, one bad row
 * would cost the whole day.
 *
 * ## Nothing here deletes
 *
 * The endpoint returns a rolling window. An order that has fallen out of it is not gone, and a failed
 * response is not an empty one — so a run that fails changes nothing at all, and the catalogue stays
 * whatever the last good import left.
 */
final readonly class CaseOrderSyncService
{
    public function __construct(
        private CaseOrderClientInterface $client,
        private CaseOrderSettings $settings,
        private CaseOrderSyncRunRepositoryInterface $runs,
        private CaseOrderParser $parser,
        private CaseOrderImporter $importer,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {}

    /*
     * Deciding whether to synchronise lives in CaseOrderSyncCoordinator, not here.
     *
     * This class used to claim the day itself. With several runs allowed a day, the decision grew
     * three independent limits — freshness, the provider's rate and the concurrency lease — and a
     * service that both decided and executed would have been the second place those rules lived. It
     * now only executes a claim somebody else made.
     */

    /**
     * Run the fetch and import for a run somebody has already claimed.
     *
     * The worker's entry point. It does no deciding: the claim is the decision, and it was made by
     * {@see CaseOrderSyncCoordinator} before this was reachable.
     */
    public function execute(CaseOrderSyncRun $run): CaseOrderSyncOutcome
    {
        return $this->fetchAndImport($run, $this->clock->now());
    }

    /**
     * Import a staged response again, without calling the provider.
     *
     * For a run that reached the provider and then failed locally. It spends no allowance, which is
     * the entire reason the payload is staged — the alternative for a malformed order or a dropped
     * connection mid-import would be to ask the provider all over again.
     */
    public function resume(int $runId): CaseOrderSyncOutcome
    {
        $run = $this->runs->find($runId);

        if ($run === null) {
            return CaseOrderSyncOutcome::failed(null, 'That synchronisation run no longer exists.');
        }

        $payload = $this->runs->stagedPayload($runId);

        if ($payload === null) {
            return CaseOrderSyncOutcome::failed(
                $run,
                'That run has no stored response to import again. A new synchronisation would have to '
                    . 'call the provider for a fresh one.',
            );
        }

        return $this->importStaged($run, $payload, $this->clock->now());
    }

    public function latest(): ?CaseOrderSyncRun
    {
        return $this->runs->latest();
    }

    // ------------------------------------------------------------------------------- the three steps

    private function fetchAndImport(CaseOrderSyncRun $run, DateTimeImmutable $now): CaseOrderSyncOutcome
    {
        try {
            $response = $this->client->fetch();
        } catch (CaseOrderFetchFailed $e) {
            /*
             * The stage records whether the request actually happened, because that is what decides
             * whether the operator may try again today. `fetch` means the provider was reached — the
             * allowance is gone. `request` means it was not.
             */
            $stage = $e->spentTheAllowance
                ? CaseOrderSyncRun::STAGE_FETCH
                : CaseOrderSyncRun::STAGE_REQUEST;

            /*
             * A 429 carries an instruction, not just a complaint.
             *
             * `retryAfterSeconds` is null unless the provider sent a readable `Retry-After`, so the
             * stored instant is the provider's own and never a wait this application invented.
             * Nothing retries: the run is finished as failed and the next attempt is a person's.
             */
            $retryAfter = $e->retryAfterSeconds === null
                ? null
                : $this->clock->now()->modify('+' . $e->retryAfterSeconds . ' seconds');

            $this->runs->fail($run->id, $stage, $e->getMessage(), $this->clock->now(), $retryAfter);

            $this->logger->warning('Case order synchronisation could not fetch.', [
                'run' => $run->id,
                'business_day' => $run->businessDay,
                'stage' => $stage,
                'http_status' => $e->httpStatus,
                // The exception's own sanitized sentence. Never a body, never a URL.
                'reason' => $e->getMessage(),
            ]);

            return CaseOrderSyncOutcome::failed($this->runs->find($run->id), $e->getMessage());
        }

        // Staged before anything is read, so the day's data survives a failure in the next step.
        $this->runs->markFetched(
            $run->id,
            $response->httpStatus,
            $response->responseBytes,
            $response->sanitizedBody,
            $this->clock->now(),
        );

        return $this->import($run, $response->orders, $now);
    }

    /** @param list<array<array-key, mixed>> $orders already sanitized by the client */
    private function import(CaseOrderSyncRun $run, array $orders, DateTimeImmutable $now): CaseOrderSyncOutcome
    {
        $this->runs->markProcessing($run->id, count($orders), $this->clock->now());

        try {
            $parsed = $this->parser->parse($orders);
            $report = $this->importer->import($parsed);
        } catch (Throwable $e) {
            /*
             * The message is this application's own class name and nothing else.
             *
             * A driver exception carries the statement and its parameters, and the parameters are a
             * customer's order. The staged payload is left in place, so this is recoverable.
             */
            $summary = 'The response was received but could not be imported (' . $e::class . '). '
                . 'The stored response can be imported again without calling the provider.';

            $this->runs->fail($run->id, CaseOrderSyncRun::STAGE_IMPORT, $summary, $this->clock->now());

            $this->logger->error('Case order synchronisation could not import.', [
                'run' => $run->id,
                'business_day' => $run->businessDay,
                'error_class' => $e::class,
            ]);

            return CaseOrderSyncOutcome::failed($this->runs->find($run->id), $summary);
        }

        $counts = [
            'inserted' => $report->inserted,
            'updated' => $report->updated,
            'skipped' => $report->skipped,
            'unchanged' => $report->unchanged,
            'items' => $report->itemsImported,
            'failures' => $report->failed,
        ];

        // Kept on a successful run too: "199 of 200, and here is the one" is a success with something
        // to read rather than a failure.
        $summary = $report->problems === [] ? null : implode(' ', $report->problems);

        /*
         * An import that wrote NOTHING and failed is a failed run, however calmly the report puts it.
         *
         * The importer steps over a bad order and carries on, which is right for a response of two
         * hundred — one malformed order must not cost the other hundred and ninety-nine. But the same
         * leniency applied to a database that is refusing every write would record a `COMPLETED` run
         * with two hundred failures, and the operator would be told the synchronisation finished.
         *
         * So the distinction is whether anything was actually stored. Some written and some failed is
         * a completed run with its failures named; none written and some failed is a failure.
         */
        if ($report->failed > 0 && $report->inserted + $report->updated === 0) {
            $failure = 'The response was received but none of its ' . $report->failed
                . ' order(s) could be stored. The stored response can be imported again without '
                . 'calling the provider.';

            $this->runs->fail($run->id, CaseOrderSyncRun::STAGE_IMPORT, $failure, $this->clock->now());

            $this->logger->error('Case order synchronisation imported nothing.', [
                'run' => $run->id,
                'business_day' => $run->businessDay,
                'failed' => $report->failed,
            ]);

            return CaseOrderSyncOutcome::failed($this->runs->find($run->id), $failure);
        }

        $this->runs->complete($run->id, $counts, $summary, $this->clock->now());

        /*
         * The payload is discarded only when the import was clean.
         *
         * It exists to make this step retryable, so keeping it after a run where nothing failed would
         * be storing a day's orders per day for a retry nobody will ask for. But a run where *some*
         * orders failed has something left to retry, and throwing the response away would turn a
         * partial import into another provider request.
         *
         * A skipped order is not a reason to keep it: a malformed order will be just as malformed on
         * the second reading.
         */
        if ($report->failed === 0) {
            $this->runs->discardStagedPayload($run->id, $this->clock->now());
        }

        $settled = $this->runs->find($run->id) ?? $run;

        return CaseOrderSyncOutcome::started($settled, $this->describe($report));
    }

    private function importStaged(
        CaseOrderSyncRun $run,
        string $payload,
        DateTimeImmutable $now,
    ): CaseOrderSyncOutcome {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $summary = 'The stored response could not be read back. A new synchronisation would have '
                . 'to call the provider.';

            $this->runs->fail($run->id, CaseOrderSyncRun::STAGE_IMPORT, $summary, $now);

            return CaseOrderSyncOutcome::failed($this->runs->find($run->id), $summary);
        }

        $orders = [];

        if (is_array($decoded)) {
            foreach ($decoded as $order) {
                if (is_array($order)) {
                    $orders[] = $order;
                }
            }
        }

        return $this->import($run, $orders, $now);
    }

    private function describe(CaseOrderImportReport $report): string
    {
        return $report->inserted . ' new, ' . $report->updated . ' updated, '
            . $report->unchanged . ' unchanged, '
            . $report->itemsImported . ' line(s) imported'
            . ($report->skipped > 0 ? ', ' . $report->skipped . ' skipped' : '')
            . ($report->failed > 0 ? ', ' . $report->failed . ' failed' : '')
            . '.';
    }

    /**
     * Today, in the configured business timezone.
     *
     * Not UTC. The client's day is not UTC's, and a run at 23:30 local must not become eligible again
     * forty minutes later because a different clock rolled over. The zone is stored on the run
     * alongside the day, so a later settings change is auditable rather than silently reinterpreting
     * what "that day" meant.
     */
    private function businessDay(DateTimeImmutable $now): string
    {
        return $now->setTimezone($this->settings->businessTimeZone())->format('Y-m-d');
    }
}
