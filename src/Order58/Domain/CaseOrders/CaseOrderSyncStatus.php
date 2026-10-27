<?php

declare(strict_types=1);

namespace App\Order58\Domain\CaseOrders;

/**
 * How far one day's Case Order synchronisation has got.
 *
 * ## Why the stages are separate rather than one "running"
 *
 * Every run costs a provider request. So the single most important thing an operator can know
 * after a failure is whether that request was actually spent — and that is exactly the difference
 * between {@see Claimed} and everything after it:
 *
 * ```
 * CLAIMED     the day is reserved, nothing has been asked of the provider yet
 * FETCHED     the response is in hand and staged. The allowance IS spent.
 * PROCESSING  reading the staged response into the tables
 * COMPLETED   done
 * FAILED      stopped; `stage` says which of the above it stopped in
 * ```
 *
 * A run that fails at `CLAIMED` failed before the socket, so retrying costs nothing. A run that fails
 * later has the response staged, so it can be retried **locally** without touching the provider.
 * Collapsing these into one word would make "can I try again today?" unanswerable.
 */
enum CaseOrderSyncStatus: string
{
    case Claimed = 'CLAIMED';
    case Fetched = 'FETCHED';
    case Processing = 'PROCESSING';
    case Completed = 'COMPLETED';
    case Failed = 'FAILED';

    public function label(): string
    {
        return match ($this) {
            self::Claimed => 'Starting',
            self::Fetched => 'Response received',
            self::Processing => 'Importing orders',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }

    /**
     * The `badge--…` variant this status wears.
     *
     * Named after the variants `admin.css` already defines rather than after the statuses, so the
     * template carries no decision about colour and no class is invented that nothing styles.
     */
    public function modifier(): string
    {
        return match ($this) {
            // `info` for everything in flight: `admin.css` defines six plain badge variants and has
            // no "running" among them, and inventing one would mean a chip nothing styles. The label
            // beside it already says which stage, so the colour only has to say "not finished yet".
            self::Claimed, self::Fetched, self::Processing => 'info',
            self::Completed => 'success',
            self::Failed => 'error',
        };
    }

    public function isFinished(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }

    /**
     * Whether reaching this stage means the day's request has been made.
     *
     * True of every stage after `CLAIMED`, because each of them describes something that happened
     * *after* the response arrived.
     *
     * **`FAILED` is the exception a caller must not read off this method.** A failure can be reached
     * from either side of the socket — "not configured" and "could not connect" are failures that
     * asked the provider nothing — so the stage is what settles it.
     * {@see CaseOrderSyncRun::allowanceSpent()} is the question an operator actually has, and it reads
     * both. This method answers about the stage in isolation and is what that one is built from.
     */
    public function hasSpentTheAllowance(): bool
    {
        return $this !== self::Claimed;
    }

    /**
     * How far along to draw the bar.
     *
     * **A stage marker, not a measured fraction**, and deliberately coarse. Three of the four steps
     * are single operations with no observable interior — there is no honest way to say a request is
     * 60% sent — so the bar moves when something actually happened rather than drifting on a timer.
     * The import stage is the one with a real denominator, and the service reports its measured share
     * separately; see {@see \App\Order58\Application\CaseOrders\CaseOrderSyncProgress}.
     */
    public function percent(): int
    {
        return match ($this) {
            self::Claimed => 5,
            self::Fetched => 40,
            self::Processing => 70,
            self::Completed, self::Failed => 100,
        };
    }
}
