<?php

declare(strict_types=1);

namespace App\Order58\Application\CaseOrders;

/**
 * The three independent limits on synchronising, kept apart on purpose.
 *
 * They answer different questions and must not be collapsed into one number:
 *
 * - **freshness** — how recently a successful run makes another one pointless. A local convenience,
 *   and a manual press bypasses it.
 * - **provider gap** — how long an AUTOMATIC caller waits between requests, so that ordinary page use
 *   cannot generate traffic the provider did not expect. A manual press bypasses it too.
 * - **lease** — how long a crashed holder is believed before its claim is reclaimed. Concurrency, not
 *   rationing.
 *
 * ## An administrator pressing the button is not rationed
 *
 * Both local limits exist to stop the *application* generating requests on its own. Neither describes
 * a limit the provider imposes, so neither is allowed to refuse a person who has decided to press the
 * button — an operator who can see the data is stale should not be told to come back tomorrow.
 *
 * ## What still cannot be bypassed
 *
 * A limit the provider itself states. When it answers HTTP 429 the run records the moment it said to
 * come back, and the coordinator honours that against every caller, manual included — see
 * {@see CaseOrderSyncCoordinator::manualBlockedReason()}. Removing our own cooldown changed what this
 * application does on its own; it granted nobody permission to exceed the provider's actual limits.
 */
final readonly class CaseOrderSyncPolicy
{
    /**
     * A day between *automatic* requests.
     *
     * Retained as the shipping default for the automatic path, where nobody is watching and a smaller
     * number turns an open browser tab into traffic. It no longer gates a manual press.
     */
    public const CONSERVATIVE_PROVIDER_GAP = 86400;

    public function __construct(
        /** Whether Load Calls may start a synchronisation at all. */
        public bool $autoSyncEnabled = true,
        /** Below this age, a completed run is reused instead of starting another. */
        public int $minRefreshSeconds = 600,
        /** The floor between AUTOMATIC requests. A manual press is not subject to it. */
        public int $minProviderGapSeconds = self::CONSERVATIVE_PROVIDER_GAP,
        /** How long a claim is believed before recovery may reclaim it. */
        public int $leaseSeconds = 900,
    ) {}
}
