<?php

declare(strict_types=1);

namespace App\Order58\Domain\CaseOrders;

use DateTimeImmutable;

/**
 * The one-a-day allowance, rationed by the database rather than by the application.
 *
 * ## Why this is not a counter or a timestamp check
 *
 * "Has it run today?" asked in PHP and then acted on is two statements with a gap between them, and
 * the gap is where the second browser tab lives. Each run costs a provider request, so a
 * second request is not a duplicate to be tidied up afterwards — it is a breach of the client's
 * agreement that cannot be taken back.
 *
 * So the claim is an `INSERT` against `UNIQUE (business_day)`. The winner gets a row; the loser gets a
 * duplicate-key error, which is reported as "already run today" and not as a fault. There is no
 * window, because there is no read.
 */
interface CaseOrderSyncRunRepositoryInterface
{
    /**
     * Reserve today, or refuse because it is already reserved.
     *
     * Returns the new run, or **null** when another run already holds the global claim.
     *
     * The claim is an INSERT against a unique index on a generated column that is non-null only while
     * a run is unfinished — so two racing callers both insert and exactly one wins. There is no read
     * before the write, and therefore no window.
     *
     * Several runs may share a `business_day`: the day is no longer the lock.
     *
     * @param string $businessDay `YYYY-MM-DD` in the configured zone, stored as given
     * @param string $triggerSource `manual`, `load-calls` or `scheduler`
     */
    public function claim(
        string $businessDay,
        string $timezone,
        string $triggerSource,
        DateTimeImmutable $leaseUntil,
        DateTimeImmutable $now,
    ): ?CaseOrderSyncRun;

    /** The run currently holding the global claim, or null when none is active. */
    public function active(): ?CaseOrderSyncRun;

    /** The most recent run that finished successfully, for the freshness question. */
    public function lastCompleted(): ?CaseOrderSyncRun;

    /**
     * Fail runs whose lease has expired, and report how many.
     *
     * **An expired lease is not proof that the provider was never called.** A process can die after
     * the request went out, so a recovered run is failed at a stage that still counts the allowance as
     * spent, and nothing is retried automatically.
     */
    public function recoverExpired(DateTimeImmutable $now): int;

    /** That day's most recent run, or null when the day has none. */
    public function forDay(string $businessDay): ?CaseOrderSyncRun;

    /**
     * One page of history, newest first.
     *
     * @return list<CaseOrderSyncRun>
     */
    public function page(int $limit, int $offset = 0): array;

    public function count(): int;

    /** The most recent run of any day, for the panel to show what happened last. */
    public function latest(): ?CaseOrderSyncRun;

    public function find(int $id): ?CaseOrderSyncRun;

    /**
     * Record that the provider answered, and keep the sanitized response.
     *
     * The payload is stored **after** redaction, by the caller, and is what lets the local half of the
     * work be retried without spending another day's allowance.
     */
    public function markFetched(
        int $id,
        int $httpStatus,
        int $responseBytes,
        string $sanitizedPayload,
        DateTimeImmutable $now,
    ): void;

    public function markProcessing(int $id, int $ordersReceived, DateTimeImmutable $now): void;

    /**
     * Record the outcome of the import.
     *
     * @param array{inserted: int, updated: int, unchanged: int, skipped: int, items: int, failures: int} $counts
     */
    public function complete(int $id, array $counts, ?string $summary, DateTimeImmutable $now): void;

    /**
     * Record a failure, naming the stage it happened in.
     *
     * The stage is what tells an operator whether the day's request was spent. The summary is this
     * application's own words — never a provider body, never a driver message, both of which can
     * carry a customer's order or a credential.
     */
    /**
     * The most recent run that the provider answered with a rate limit, elapsed or not.
     *
     * Null when the provider has never said so. The caller compares the instant against the clock;
     * the repository does not, so a reader cannot be handed a restriction it did not ask about.
     */
    public function lastProviderRateLimited(): ?CaseOrderSyncRun;

    public function fail(
        int $id,
        string $stage,
        string $summary,
        DateTimeImmutable $now,
        /**
         * When the provider said it would accept another request, when it said so at all.
         *
         * Defaulted so every existing caller keeps compiling: a failure that is not a rate limit has
         * nothing to record here, and passing null must never clear an instruction already stored.
         */
        ?DateTimeImmutable $providerRetryAfter = null,
    ): void;

    /** The sanitized response of one run, or null when none is stored. */
    public function stagedPayload(int $id): ?string;

    /**
     * Forget a run's staged response once it is no longer needed.
     *
     * Called after a successful import. The payload exists to make the local half retryable, and a
     * completed run has nothing left to retry — keeping a day's worth of orders per day forever would
     * grow a table nobody reads.
     */
    public function discardStagedPayload(int $id, DateTimeImmutable $now): void;
}
