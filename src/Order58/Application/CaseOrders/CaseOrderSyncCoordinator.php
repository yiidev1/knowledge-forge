<?php

declare(strict_types=1);

namespace App\Order58\Application\CaseOrders;

use App\Order58\Client\CaseOrders\CaseOrderSettings;
use App\Order58\Domain\CaseOrders\CaseOrderSyncRun;
use App\Order58\Domain\CaseOrders\CaseOrderSyncRunRepositoryInterface;
use App\Shared\Domain\Clock\ClockInterface;
use DateTimeImmutable;

use function implode;
use function max;

/**
 * The ONE place that decides whether to synchronise case orders.
 *
 * Both entry points — the manual button and Load Calls — come through here, so the rules live once.
 * Spreading them across two web actions is how two surfaces end up disagreeing about whether a run is
 * due, and how a provider that permits few requests receives several.
 *
 * ## It starts work; it never does work
 *
 * A caller gets a decision and a run id back immediately. The fetch and the import happen in the
 * worker. That is what keeps a provider call out of a web request, and it is also why no caller can
 * recurse into another: nothing here calls a controller, and the worker calls nothing back.
 *
 * ## The order of the gates, which is the whole design
 *
 * ```
 * 1. configured?        a missing token costs a message, never a claim
 * 2. enabled?           only for automatic callers; a manual press is an explicit instruction
 * 3. already running?   join it. Two admins pressing together watch one run
 * 4. provider said no?  a 429's own Retry-After. NOT bypassable, by anyone, because it is not ours
 * 5. our own gap        automatic callers only; a manual press is not rationed by us
 * 6. fresh enough?      automatic callers only, for the same reason
 * 7. claim              an INSERT that either wins or loses; no read-before-write
 * ```
 *
 * Gates 5 and 6 are limits this application invented to stop *itself* generating traffic. Neither
 * describes something the provider asked for, so neither refuses a person who has pressed the button.
 * Gate 4 is the provider's own word and binds everybody.
 *
 * Recovery runs first, so a crashed holder cannot block forever — but an expired lease is closed as
 * *abandoned*, which still counts the allowance as spent. Nothing is retried automatically.
 */
final readonly class CaseOrderSyncCoordinator
{
    public function __construct(
        private CaseOrderSyncRunRepositoryInterface $runs,
        private CaseOrderSettings $settings,
        private CaseOrderSyncPolicy $policy,
        private ClockInterface $clock,
    ) {}

    /**
     * Ask for a synchronisation, and be told what happened.
     *
     * @param string $trigger `manual`, `load-calls` or `scheduler`
     * @param bool   $manual  true bypasses the freshness window — never the provider's rate
     */
    public function request(string $trigger, bool $manual = false): CaseOrderSyncDecision
    {
        $problems = $this->settings->problems();

        if ($problems !== []) {
            return CaseOrderSyncDecision::notAllowed(implode(' ', $problems));
        }

        if (!$manual && !$this->policy->autoSyncEnabled) {
            return CaseOrderSyncDecision::notAllowed(
                'Automatic case order synchronisation is switched off '
                . '(ORDER58_CASE_ORDER_AUTO_SYNC_ENABLED).',
            );
        }

        $now = $this->clock->now();

        // Before anything else, so a dead holder cannot hold the claim for ever.
        $this->runs->recoverExpired($now);

        $active = $this->runs->active();

        if ($active !== null) {
            // Joined, not refused. Two administrators pressing together, or five stores loading calls
            // at once, all watch the one run — which is the point of a global synchronisation.
            return CaseOrderSyncDecision::joined($active);
        }

        /*
         * The provider's own instruction, honoured before anything else is weighed.
         *
         * Read from the most recent run that received a 429 rather than from configuration, because
         * it is a fact about a moment somebody else chose. A manual press does not lift it: removing
         * this application's cooldown said nothing about the provider's.
         */
        $held = $this->providerHold($now);

        if ($held !== null) {
            return CaseOrderSyncDecision::rateLimited($held[0], $held[1]);
        }

        $last = $this->runs->lastCompleted();

        if ($last !== null && !$manual) {
            $age = $this->secondsSince($last->completedAt, $now);

            /*
             * Both remaining limits are ours, and both apply to automatic callers only.
             *
             * An administrator who can see the data is stale may press the button as often as they
             * like; what these stop is an idle page, a filter or a status poll turning into traffic.
             */
            if ($age !== null && $age < $this->policy->minProviderGapSeconds) {
                return CaseOrderSyncDecision::rateLimited($last, $this->gapMessage(
                    $this->policy->minProviderGapSeconds - $age,
                ));
            }

            if ($age !== null && $age < $this->policy->minRefreshSeconds) {
                return CaseOrderSyncDecision::fresh(
                    $last,
                    'Case orders were synchronised ' . $this->minutes($age) . ' ago, which is recent '
                        . 'enough. The stored data is being used.',
                );
            }
        }

        $run = $this->runs->claim(
            $this->businessDay($now),
            $this->settings->timezone,
            $trigger,
            $now->modify('+' . max(60, $this->policy->leaseSeconds) . ' seconds'),
            $now,
        );

        if ($run === null) {
            // Lost the race against another caller between the check above and this insert — which is
            // exactly the window a check alone would have left open. The index closed it.
            $running = $this->runs->active();

            return $running === null
                ? CaseOrderSyncDecision::notAllowed('The synchronisation could not be claimed.')
                : CaseOrderSyncDecision::joined($running);
        }

        return CaseOrderSyncDecision::started($run);
    }

    /**
     * Why a manual synchronisation cannot start right now, or null when it can.
     *
     * A page render must not decide to synchronise, so this claims nothing and recovers nothing. It
     * restates the three gates in {@see request()} that a manual press cannot talk its way past —
     * not configured, a run already in flight, and the provider's rate — and deliberately omits the
     * freshness gate, because waiving that is precisely what "manual" means.
     *
     * An expired lease is not counted as in flight. `request()` recovers it before it looks, so a
     * holder that died must not leave the button inert until somebody notices; reporting it as
     * running here would describe a state the next press would not find.
     */
    public function manualBlockedReason(): ?string
    {
        $problems = $this->settings->problems();

        if ($problems !== []) {
            return implode(' ', $problems);
        }

        $now = $this->clock->now();
        $active = $this->runs->active();

        if ($active !== null && ($active->leaseExpiresAt === null || $active->leaseExpiresAt > $now)) {
            return 'A case order synchronisation is already running.';
        }

        $held = $this->providerHold($now);

        return $held === null ? null : $held[1];
    }

    /**
     * The provider's unexpired "come back later", if one is on record.
     *
     * Looked up from the run that received it — the most recent run carrying a `provider_retry_after`
     * — and ignored once the instant has passed, so a limit lapses on its own rather than needing
     * somebody to clear it.
     *
     * @return array{0: ?CaseOrderSyncRun, 1: string}|null the run that was told, and what to say
     */
    private function providerHold(DateTimeImmutable $now): ?array
    {
        $run = $this->runs->lastProviderRateLimited();
        $until = $run?->providerRetryAfter;

        if ($run === null || $until === null || $until <= $now) {
            return null;
        }

        return [$run, 'The provider refused the last request as too frequent and asked for another '
            . 'no sooner than ' . $this->minutes($this->secondsSince($now, $until) ?? 0)
            . ' from now. This is the provider\'s own limit, not a setting of this application, so a '
            . 'manual synchronisation does not lift it.'];
    }

    /** Today's most recent run, for a panel that wants to show something without asking for work. */
    public function latest(): ?CaseOrderSyncRun
    {
        return $this->runs->latest();
    }

    public function active(): ?CaseOrderSyncRun
    {
        return $this->runs->active();
    }

    public function find(int $id): ?CaseOrderSyncRun
    {
        return $this->runs->find($id);
    }

    private function secondsSince(?DateTimeImmutable $then, DateTimeImmutable $now): ?int
    {
        return $then === null ? null : max(0, $now->getTimestamp() - $then->getTimestamp());
    }

    /** This application's own gap between AUTOMATIC requests. A person pressing the button never sees it. */
    private function gapMessage(int $wait): string
    {
        return 'Automatic synchronisation is spaced out and the next one is due in about '
            . $this->minutes($wait) . '. The stored data is being used. Synchronise now to refresh it '
            . 'immediately — a manual synchronisation is not subject to this interval.';
    }

    private function minutes(int $seconds): string
    {
        if ($seconds < 120) {
            return $seconds . ' second(s)';
        }

        $minutes = (int) ($seconds / 60);

        return $minutes < 120 ? $minutes . ' minute(s)' : (int) ($minutes / 60) . ' hour(s)';
    }

    private function businessDay(DateTimeImmutable $now): string
    {
        return $now->setTimezone($this->settings->businessTimeZone())->format('Y-m-d');
    }
}
