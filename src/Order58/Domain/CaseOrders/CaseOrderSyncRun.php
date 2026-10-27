<?php

declare(strict_types=1);

namespace App\Order58\Domain\CaseOrders;

use DateTimeImmutable;

/**
 * One day's Case Order synchronisation, as it stands.
 *
 * The row is the allowance: `business_day` is UNIQUE, so the insert that creates this *is* the rate
 * limit — two tabs, two operators and a scheduler all race on the same index and exactly one wins. See
 * {@see CaseOrderSyncRunRepositoryInterface::claim()}.
 *
 * The staged response is **not** carried here. It is up to sixteen megabytes and this object is read
 * by a status endpoint that a browser polls every few seconds; the repository hands the payload over
 * separately, to the one caller that processes it.
 */
final readonly class CaseOrderSyncRun
{
    /*
     * The stages a failure can be recorded at.
     *
     * Named here rather than written out at each site because {@see allowanceSpent()} turns on one of
     * them, and a typo in that comparison would quietly tell an operator the day was spent when it
     * was not — which costs a day — or unspent when it was, which costs a second request to a
     * provider that permits one.
     */

    /** Refused before the socket: not configured, or the transport never connected. */
    public const STAGE_REQUEST = 'request';

    /** The provider answered, and the answer was a refusal or unreadable. */
    public const STAGE_FETCH = 'fetch';

    /** The response is in hand and staged; reading it into the tables failed. */
    public const STAGE_IMPORT = 'import';

    /**
     * The holder stopped reporting and its lease expired.
     *
     * Deliberately NOT `request`: a process can die after the request went out, so this stage counts
     * the allowance as spent. Treating it as unspent would invite a second call.
     */
    public const STAGE_ABANDONED = 'abandoned';

    public function __construct(
        public int $id,
        /** The day this run claimed, as `YYYY-MM-DD` in {@see $timezone}. */
        public string $businessDay,
        /** The zone that day was computed in, kept so a later settings change stays auditable. */
        public string $timezone,
        public CaseOrderSyncStatus $status,
        /** Which stage a failure happened in, or null while nothing has failed. */
        public ?string $stage,
        /** `manual`, `load-calls` or `scheduler` — what asked for this run. */
        public string $triggerSource,
        public DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $fetchedAt,
        public ?DateTimeImmutable $completedAt,
        /** When a crashed holder stops being believed. Null once the run has finished. */
        public ?DateTimeImmutable $leaseExpiresAt,
        public ?int $httpStatus,
        /**
         * When the PROVIDER said it would accept another request, or null when it never said.
         *
         * Set only from a `Retry-After` on a 429. This is the one limit a manual press may not
         * bypass, because it is not ours to waive — see {@see CaseOrderSyncPolicy}.
         */
        public ?int $responseBytes,
        public int $ordersReceived,
        public int $ordersInserted,
        public int $ordersUpdated,
        public int $ordersSkipped,
        /** Orders the provider returned whose stored copy was already identical. */
        public int $ordersUnchanged,
        public int $itemsImported,
        public int $failures,
        /** One sanitized sentence for an operator. Never a token, never a customer's details. */
        public ?string $errorSummary,
        /** Whether the sanitized response is still stored, so the local half can be retried. */
        public bool $hasStagedPayload,
        /**
         * When the PROVIDER said it would accept another request, or null when it never said.
         *
         * Set only from a `Retry-After` on an HTTP 429. This is the one limit a manual press may not
         * bypass, because it is not ours to waive — see {@see CaseOrderSyncPolicy}. Defaulted and
         * last because almost every run has no such instruction, so a reader that does not care
         * about rate limiting never has to mention it.
         */
        public ?DateTimeImmutable $providerRetryAfter = null,
    ) {}

    /** How many orders were written, whether as inserts or updates. */
    public function ordersWritten(): int
    {
        return $this->ordersInserted + $this->ordersUpdated;
    }

    /**
     * Whether today's single request to the provider was actually made.
     *
     * **The status alone cannot answer this, and that is why the method is here.**
     * `CaseOrderSyncStatus::hasSpentTheAllowance()` reads the stages in order and says that anything
     * past `CLAIMED` has been paid for, which is true of the three stages that describe progress. It
     * is not true of `FAILED`, which can be reached from either side of the socket: a run that failed
     * because the endpoint was not configured, or because the transport never connected, never asked
     * the provider anything.
     *
     * Getting it wrong is expensive in both directions. Reporting an unspent day as spent costs the
     * client a day of imports for no reason. Reporting a spent day as unspent invites a second
     * request, which breaches an agreement rather than merely wasting time.
     */
    public function allowanceSpent(): bool
    {
        if ($this->status === CaseOrderSyncStatus::Failed) {
            return $this->stage !== self::STAGE_REQUEST;
        }

        return $this->status->hasSpentTheAllowance();
    }

    /**
     * Whether another attempt today would have to call the provider.
     *
     * The useful question after a failure, and the only one that costs anything. A run that failed
     * before the request can be retried outright; one that failed after it has the response staged
     * and can be re-imported locally.
     */
    public function canRetryWithoutCallingTheProvider(): bool
    {
        return $this->status === CaseOrderSyncStatus::Failed && $this->hasStagedPayload;
    }
}
