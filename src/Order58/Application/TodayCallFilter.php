<?php

declare(strict_types=1);

namespace App\Order58\Application;

use App\Integration\Order58Recording\CallSummary;
use App\Shared\Application\Time\AppTimeZone;
use DateTimeImmutable;

/**
 * Which of the provider's recent calls belong to "today", and which of those can be fetched at all.
 *
 * ## The date is the call's own, never the server's
 *
 * Every call carries a `callTime`, and {@see CallSummary::derivedDate()} reads a `YYYY-MM-DD` from it
 * **only when it is certain** — a real calendar date at the start of the string, or nothing. That date is
 * what the recording fetch is made with, and it is what a call is compared against here.
 *
 * Using today's date for the fetch instead would work until the first retry after midnight, at which
 * point every queued recording would start asking the provider for the wrong day. The call's own date is
 * fixed when the batch is created and stays correct.
 *
 * ## The assumption, stated
 *
 * `callTime`'s timezone is **not documented by the provider**. This compares its leading date against
 * `AppTimeZone::businessDate()` — the same America/New_York calendar day the daily sync schedulers use —
 * and converts nothing. If the provider turns out to publish UTC, the list can be wrong near midnight:
 * an early-morning call would be filed under the previous day. That is visible rather than silent, since
 * the page prints each call's real time beside it, and it does not affect a fetch — the date sent is the
 * one the provider itself printed.
 *
 * ## A call with no readable date is not offered
 *
 * It cannot be fetched, because there is no `time=` to send. Refusing it is the only honest answer;
 * substituting today's date would produce a confident request for a recording that is not there.
 */
final readonly class TodayCallFilter
{
    public function __construct(
        private AppTimeZone $appTimeZone,
    ) {}

    /**
     * The calls from `$calls` that belong to the current business day and can be acted on.
     *
     * @param list<CallSummary> $calls as the provider returned them
     *
     * @return list<CallSummary>
     */
    public function today(array $calls, DateTimeImmutable $now): array
    {
        return $this->onDate($calls, $this->businessDate($now));
    }

    /**
     * The calls belonging to one named `YYYY-MM-DD`.
     *
     * Separate from {@see today()} so a future backfill can ask for another day without re-deriving what
     * "today" means, and so a test can pin the rule without moving a clock.
     *
     * @param list<CallSummary> $calls
     *
     * @return list<CallSummary>
     */
    public function onDate(array $calls, string $date): array
    {
        $matching = [];

        foreach ($calls as $call) {
            // Usable first: a row with no session id has nothing to import, and one whose id the
            // recording request would refuse must not be offered as though it could be fetched.
            if (!$call->isUsable() || !$call->hasValidRecordingId()) {
                continue;
            }

            if ($call->derivedDate() === $date) {
                $matching[] = $call;
            }
        }

        return $matching;
    }

    /** The day the page is showing, for its heading. */
    public function businessDate(DateTimeImmutable $now): string
    {
        return $this->appTimeZone->businessDate($now);
    }
}
