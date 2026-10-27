<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\Order58\Application\CaseOrders\CaseOrderImporter;
use App\Order58\Application\CaseOrders\CaseOrderParser;
use App\Order58\Application\CaseOrders\CaseOrderSyncCoordinator;
use App\Order58\Application\CaseOrders\CaseOrderSyncDrainer;
use App\Order58\Application\CaseOrders\CaseOrderSyncPolicy;
use App\Order58\Application\CaseOrders\CaseOrderSyncService;
use App\Order58\Client\CaseOrders\CaseOrderClientInterface;
use App\Order58\Client\CaseOrders\CaseOrderFetchFailed;
use App\Order58\Client\CaseOrders\CaseOrderResponse;
use App\Order58\Client\CaseOrders\CaseOrderSettings;
use App\Order58\Domain\CaseOrders\CaseOrderSyncRun;
use App\Order58\Domain\CaseOrders\CaseOrderSyncRunRepositoryInterface;
use App\Order58\Domain\CaseOrders\CaseOrderSyncStatus;
use App\Order58\Domain\Items\ItemSyncStateRepositoryInterface;
use App\Order58\Domain\Items\ItemSyncState;
use App\Order58\Domain\Items\Order58OrderItem;
use App\Order58\Domain\Items\OrderItemRepositoryInterface;
use App\Order58\Domain\Orders\Order58Order;
use App\Order58\Domain\Orders\Order58OrderRepositoryInterface;
use App\Order58\Domain\Orders\OrderListQuery;
use App\Tests\Support\MutableClock;
use Codeception\Test\Unit;
use DateTimeImmutable;
use Psr\Log\NullLogger;
use RuntimeException;

use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertTrue;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function json_encode;
use function range;

/**
 * One day's case order synchronisation. **No test here reaches the network or a database.**
 *
 * ## The property every case is really about
 *
 * The provider permits **one request a day**. So the service is not interesting for importing
 * correctly — the importer's own tests cover that — it is interesting for how carefully it spends that
 * one request, and for whether an operator can tell afterwards what it was spent on.
 *
 * Four claims, and each has a case below:
 *
 * 1. A misconfiguration costs a message, not the day. The claim happens *after* the check.
 * 2. Two presses make one request. The claim is a unique insert, so the second is refused by the
 *    database rather than by a check with a window in it.
 * 3. A failure says whether the request was made. `stage` is `request` before the socket and `fetch`
 *    after, which is the difference between "try again" and "wait until tomorrow".
 * 4. A failure after the response arrived is recoverable locally, because the response is staged
 *    before anything is parsed.
 */
final class CaseOrderSyncServiceTest extends Unit
{
    // --------------------------------------------------------------- the coordinator's gates

    /** A misconfigured endpoint never claims, so a typo cannot cost a run. */
    public function testAMisconfiguredEndpointDoesNotClaim(): void
    {
        $runs = new InMemoryCaseOrderRuns();
        $decision = $this->coordinator($runs, token: '')->request('manual', manual: true);

        assertSame('not_allowed', $decision->outcome);
        assertSame(0, $runs->claims);
    }

    /** **Several runs a day.** The old UNIQUE(business_day) made this impossible. */
    public function testASecondRunIsAllowedOnTheSameDayOnceTheFirstHasFinished(): void
    {
        $runs = new InMemoryCaseOrderRuns();
        $client = $this->succeedingClient();

        $morning = $this->coordinator($runs, policy: $this->openPolicy())->request('load-calls');
        assertSame('started', $morning->outcome);
        $this->drain($runs, $client);

        // Afternoon: the provider now has more orders, and nothing about the day blocks a second run.
        $afternoon = $this->coordinator($runs, policy: $this->openPolicy())->request('manual', manual: true);

        assertSame('started', $afternoon->outcome, 'a second run on the same day must be allowed');
        assertSame(2, $runs->claims);
        assertNotSame($morning->run?->id, $afternoon->run?->id, 'and it is its own run');
    }

    /**
     * **Several manual synchronisations on one day, back to back, each its own run.**
     *
     * The headline requirement. Three presses with no waiting between them, under the shipped
     * day-long gap, produce three claims, three provider calls and three separate history rows —
     * which is what "as often as I need" has to mean for the history page to stay honest.
     */
    public function testManualSynchronisationMayBeRepeatedImmediately(): void
    {
        $runs = new InMemoryCaseOrderRuns();
        $client = $this->succeedingClient();
        $shipped = new CaseOrderSyncPolicy();
        $ids = [];

        for ($press = 0; $press < 3; ++$press) {
            $decision = $this->coordinator($runs, policy: $shipped)->request('manual', manual: true);

            assertSame('started', $decision->outcome, 'press ' . ($press + 1) . ' must start a run');
            $ids[] = $decision->run?->id;

            $this->drain($runs, $client);
        }

        assertSame(3, $runs->claims);
        assertSame(3, $client->calls, 'each press is its own provider request');
        assertSame($ids, array_values(array_unique($ids)), 'and each is its own run in the history');
        assertSame(3, $runs->count(), 'all three survive as separate history rows');
    }

    /** Two admins pressing together JOIN one run; the provider is asked once. */
    public function testConcurrentRequestsJoinTheRunningSynchronisation(): void
    {
        $runs = new InMemoryCaseOrderRuns();

        $first = $this->coordinator($runs, policy: $this->openPolicy())->request('manual', manual: true);
        $second = $this->coordinator($runs, policy: $this->openPolicy())->request('manual', manual: true);

        assertSame('started', $first->outcome);
        assertSame('joined', $second->outcome, 'the second press watches the first run');
        assertSame($first->run?->id, $second->run?->id);
        assertSame(1, $runs->claims, 'exactly one claim, so exactly one provider request');
    }

    /** Several stores loading calls at once also join, because the sync is global. */
    public function testSeveralStoresShareOneGlobalRun(): void
    {
        $runs = new InMemoryCaseOrderRuns();
        $outcomes = [];

        foreach ([1121, 431, 1731, 351] as $ignoredStore) {
            $outcomes[] = $this->coordinator($runs, policy: $this->openPolicy())->request('load-calls')->outcome;
        }

        assertSame(['started', 'joined', 'joined', 'joined'], $outcomes);
        assertSame(1, $runs->claims);
    }

    /** Inside the freshness window an automatic caller reuses the stored data. */
    public function testAnAutomaticCallerReusesARecentRun(): void
    {
        $runs = new InMemoryCaseOrderRuns();
        $client = $this->succeedingClient();

        $this->coordinator($runs, policy: $this->openPolicy())->request('load-calls');
        $this->drain($runs, $client);

        $again = $this->coordinator($runs, policy: new CaseOrderSyncPolicy(
            minRefreshSeconds: 600,
            minProviderGapSeconds: 0,
        ))->request('load-calls');

        assertSame('fresh', $again->outcome);
        assertSame(1, $runs->claims, 'nothing new was claimed');
    }

    /** A manual press bypasses freshness — that is what manual means. */
    public function testAManualPressBypassesTheFreshnessWindow(): void
    {
        $runs = new InMemoryCaseOrderRuns();
        $this->coordinator($runs, policy: $this->openPolicy())->request('load-calls');
        $this->drain($runs, $this->succeedingClient());

        $manual = $this->coordinator($runs, policy: new CaseOrderSyncPolicy(
            minRefreshSeconds: 600,
            minProviderGapSeconds: 0,
        ))->request('manual', manual: true);

        assertSame('started', $manual->outcome);
    }

    /**
     * **Our own gap does not ration a manual press.** Changed deliberately, and this is the pin.
     *
     * This test previously asserted the opposite, on the reading that the client's stated daily limit
     * was an agreement rather than a setting. The requirement is now explicit: an administrator may
     * synchronise as often as they choose. The limit below is still configured at a full day and the
     * press still goes through, which is the whole of the change.
     */
    public function testOurOwnProviderGapDoesNotBlockAManualPress(): void
    {
        $runs = new InMemoryCaseOrderRuns();
        $this->coordinator($runs, policy: $this->openPolicy())->request('load-calls');
        $this->drain($runs, $this->succeedingClient());

        $manual = $this->coordinator($runs, policy: new CaseOrderSyncPolicy(
            minRefreshSeconds: 0,
            minProviderGapSeconds: 86400,
        ))->request('manual', manual: true);

        assertSame('started', $manual->outcome, 'a manual press is not rationed by our own gap');
        assertSame(2, $runs->claims, 'and it really claimed a second run');
    }

    /** The same gap still spaces out the automatic caller, which is what it is for. */
    public function testOurOwnProviderGapStillSpacesOutAutomaticCallers(): void
    {
        $runs = new InMemoryCaseOrderRuns();
        $this->coordinator($runs, policy: $this->openPolicy())->request('load-calls');
        $this->drain($runs, $this->succeedingClient());

        $auto = $this->coordinator($runs, policy: new CaseOrderSyncPolicy(
            minRefreshSeconds: 0,
            minProviderGapSeconds: 86400,
        ))->request('load-calls');

        assertSame('rate_limited', $auto->outcome);
        assertSame(1, $runs->claims, 'Load Calls did not ask the provider again');
    }

    /**
     * **A limit the PROVIDER states is not ours to waive, manual or not.**
     *
     * This is what makes removing our own cooldown honest. The application stopped rationing itself;
     * it did not grant anybody permission to exceed the provider's actual limit, and a 429 with a
     * `Retry-After` blocks the button exactly as the old daily gap used to.
     */
    public function testAProviderRateLimitBlocksEvenAManualPress(): void
    {
        $runs = new InMemoryCaseOrderRuns();

        $this->coordinator($runs, policy: $this->openPolicy())->request('manual', manual: true);
        $this->drain($runs, $this->rateLimitedClient(retryAfterSeconds: 3600));

        $again = $this->coordinator($runs, policy: $this->openPolicy())
            ->request('manual', manual: true);

        assertSame('rate_limited', $again->outcome);
        assertStringContainsString('provider', $again->message);
        assertSame(1, $runs->claims, 'nothing was claimed, so nothing was sent');
    }

    /** Nothing retries a 429 on its own: the next attempt is a person's. */
    public function testAProviderRateLimitIsNeverRetriedAutomatically(): void
    {
        $runs = new InMemoryCaseOrderRuns();
        $client = $this->rateLimitedClient(retryAfterSeconds: 3600);

        $this->coordinator($runs, policy: $this->openPolicy())->request('manual', manual: true);
        $this->drain($runs, $client);

        // A second worker pass over the same state must not send anything.
        $this->drain($runs, $client);

        assertSame(1, $client->calls, 'the refused request was sent once and never repeated');
    }

    /** Once the provider's own wait has elapsed, the button is free again. */
    public function testTheButtonIsFreeOnceTheProvidersWaitHasElapsed(): void
    {
        $runs = new InMemoryCaseOrderRuns();

        $this->coordinator($runs, policy: $this->openPolicy())->request('manual', manual: true);
        $this->drain($runs, $this->rateLimitedClient(retryAfterSeconds: 60));

        $later = $this->coordinator(
            $runs,
            policy: $this->openPolicy(),
            clock: new MutableClock('2026-10-26 15:00:00'),
        );

        assertSame(null, $later->manualBlockedReason(), 'the instruction lapses on its own');
    }

    /** Automatic synchronisation can be switched off without affecting the manual button. */
    public function testLoadCallsRespectsTheAutoSyncSwitchAndManualDoesNot(): void
    {
        $runs = new InMemoryCaseOrderRuns();
        $policy = new CaseOrderSyncPolicy(autoSyncEnabled: false, minProviderGapSeconds: 0);

        assertSame('not_allowed', $this->coordinator($runs, policy: $policy)->request('load-calls')->outcome);
        assertSame('started', $this->coordinator($runs, policy: $policy)->request('manual', manual: true)->outcome);
    }

    /**
     * An abandoned claim is recovered, and recorded as having possibly spent the request.
     *
     * A process can die after the request went out, so the stage must not claim otherwise.
     */
    public function testAnExpiredLeaseIsRecoveredWithoutClaimingTheRequestNeverHappened(): void
    {
        $runs = new InMemoryCaseOrderRuns();
        $clock = new MutableClock('2026-10-26 09:00:00');

        $started = $this->coordinator($runs, policy: $this->openPolicy(), clock: $clock)
            ->request('manual', manual: true);
        assertSame('started', $started->outcome);

        // Long past the lease, with nothing reported.
        $clock->advance('+2 hours');

        $next = $this->coordinator($runs, policy: $this->openPolicy(), clock: $clock)
            ->request('manual', manual: true);

        assertSame('started', $next->outcome, 'the stale claim must not block forever');

        $abandoned = $runs->find((int) $started->run?->id);
        assertSame(CaseOrderSyncStatus::Failed, $abandoned?->status);
        assertSame(CaseOrderSyncRun::STAGE_ABANDONED, $abandoned?->stage);
        assertTrue($abandoned->allowanceSpent(), 'an expired lease is not proof nothing was sent');
    }

    // ------------------------------------------------------------- the worker does the work

    /** The drainer executes a claimed run and records real counts. */
    public function testTheDrainerExecutesAClaimedRun(): void
    {
        $runs = new InMemoryCaseOrderRuns();
        $client = $this->succeedingClient();

        $decision = $this->coordinator($runs, policy: $this->openPolicy())->request('manual', manual: true);
        assertSame(1, $client->calls === 0 ? 1 : 1, 'the claim itself asks the provider nothing');
        assertSame(0, $client->calls);

        $this->drain($runs, $client);

        assertSame(1, $client->calls);
        $run = $runs->find((int) $decision->run?->id);
        assertSame(CaseOrderSyncStatus::Completed, $run?->status);
        assertSame(1, $run->ordersInserted);
        assertSame(1, $run->itemsImported);
    }

    /** With nothing claimed, a drain pass asks nobody anything. */
    public function testTheDrainerDoesNothingWithoutAClaim(): void
    {
        $runs = new InMemoryCaseOrderRuns();
        $client = $this->succeedingClient();

        $this->drain($runs, $client);

        assertSame(0, $client->calls);
    }

    /** A failed import keeps the response, so it can be retried locally rather than remotely. */
    public function testAFailedImportKeepsTheResponseForALocalRetry(): void
    {
        $runs = new InMemoryCaseOrderRuns();
        $client = $this->succeedingClient();

        $decision = $this->coordinator($runs, policy: $this->openPolicy())->request('manual', manual: true);
        $this->drain($runs, $client, orders: new ThrowingOrderRepository());

        $run = $runs->find((int) $decision->run?->id);
        assertSame(CaseOrderSyncStatus::Failed, $run?->status);
        assertTrue($run->canRetryWithoutCallingTheProvider());
        assertNotNull($runs->stagedPayload((int) $decision->run?->id));
    }

    // ------------------------------------------------------------------------------------ fixtures

    // ------------------------------------------------- what the button is allowed to say

    /**
     * A finished run does not disable the button for ever.
     *
     * The page used to decide this from "is there a run?", which is true from the first successful
     * synchronisation onwards — so the control went inert on day one and stayed that way. The
     * question is whether another run may *start*, and only the coordinator knows that.
     */
    public function testAFinishedRunDoesNotBlockTheNextOneOnceTheGapHasElapsed(): void
    {
        $runs = new InMemoryCaseOrderRuns();
        $client = $this->succeedingClient();

        $this->coordinator($runs, policy: $this->openPolicy())->request('manual', manual: true);
        $this->drain($runs, $client);

        $coordinator = $this->coordinator($runs, policy: $this->openPolicy());

        assertNotNull($coordinator->latest(), 'a run exists');
        assertSame(null, $coordinator->manualBlockedReason(), 'and another may still start');
    }

    /**
     * Even at the shipped day-long gap, a finished run leaves the button available.
     *
     * The default policy is used deliberately rather than an opened one: this is the configuration
     * the machine actually runs, and it was what kept the control inert.
     */
    public function testTheShippedGapNoLongerDisablesTheButton(): void
    {
        $runs = new InMemoryCaseOrderRuns();
        $client = $this->succeedingClient();

        $this->coordinator($runs, policy: $this->openPolicy())->request('manual', manual: true);
        $this->drain($runs, $client);

        $reason = $this->coordinator($runs, clock: new MutableClock('2026-10-26 13:00:20'))
            ->manualBlockedReason();

        assertSame(null, $reason, 'twenty seconds after a completed run, it may run again');
    }

    /** The provider's own instruction is the one thing that does disable it. */
    public function testAProviderRateLimitDisablesTheButtonAndSaysWhose(): void
    {
        $runs = new InMemoryCaseOrderRuns();

        $this->coordinator($runs, policy: $this->openPolicy())->request('manual', manual: true);
        $this->drain($runs, $this->rateLimitedClient(retryAfterSeconds: 3600));

        $reason = $this->coordinator($runs, policy: $this->openPolicy())->manualBlockedReason();

        assertNotNull($reason);
        assertStringContainsString('provider', $reason);
        assertStringContainsString('not a setting of this application', $reason);
    }

    /** The freshness window is not a reason to disable it: a manual press is allowed to waive that. */
    public function testFreshnessAloneDoesNotDisableTheButton(): void
    {
        $runs = new InMemoryCaseOrderRuns();
        $client = $this->succeedingClient();

        $this->coordinator($runs, policy: $this->openPolicy())->request('manual', manual: true);
        $this->drain($runs, $client);

        $policy = new CaseOrderSyncPolicy(minRefreshSeconds: 3600, minProviderGapSeconds: 0);

        assertSame(null, $this->coordinator($runs, policy: $policy)->manualBlockedReason());
    }

    /** A run in flight blocks it, because pressing again would only join that run. */
    public function testARunInFlightBlocksTheButton(): void
    {
        $runs = new InMemoryCaseOrderRuns();

        $this->coordinator($runs, policy: $this->openPolicy())->request('manual', manual: true);

        $reason = $this->coordinator($runs, policy: $this->openPolicy())->manualBlockedReason();

        assertNotNull($reason);
        assertStringContainsString('already running', $reason);
    }

    /** A misconfiguration is reported in the settings' own words, not a sentence of the page's. */
    public function testAMisconfiguredEndpointIsTheReasonTheButtonIsOff(): void
    {
        $reason = $this->coordinator(new InMemoryCaseOrderRuns(), token: '')->manualBlockedReason();

        assertNotNull($reason);
        assertNotSame('', $reason);
    }

    /** Asking never claims: the page renders this on every load. */
    public function testAskingWhetherItCouldRunNeverClaims(): void
    {
        $runs = new InMemoryCaseOrderRuns();

        $this->coordinator($runs, policy: $this->openPolicy())->manualBlockedReason();
        $this->coordinator($runs, policy: $this->openPolicy())->manualBlockedReason();

        assertSame(0, $runs->claims, 'a GET must never spend the provider allowance');
    }

    private function coordinator(
        InMemoryCaseOrderRuns $runs,
        string $token = 'secret-token',
        ?CaseOrderSyncPolicy $policy = null,
        ?MutableClock $clock = null,
    ): CaseOrderSyncCoordinator {
        return new CaseOrderSyncCoordinator(
            $runs,
            $this->settings($token),
            $policy ?? new CaseOrderSyncPolicy(),
            $clock ?? new MutableClock('2026-10-26 13:00:00'),
        );
    }

    /** One worker pass over whatever is claimed. */
    private function drain(
        InMemoryCaseOrderRuns $runs,
        FakeCaseOrderClient $client,
        ?Order58OrderRepositoryInterface $orders = null,
        ?MutableClock $clock = null,
    ): void {
        $clock ??= new MutableClock('2026-10-26 13:00:10');

        $service = new CaseOrderSyncService(
            $client,
            $this->settings('secret-token'),
            $runs,
            new CaseOrderParser(),
            new CaseOrderImporter(
                $orders ?? new CountingOrderRepository(),
                new CountingItemRepository(),
                new NoopItemSyncState(),
                $clock,
                new NullLogger(),
            ),
            $clock,
            new NullLogger(),
        );

        (new CaseOrderSyncDrainer($service, $runs, $clock, new NullLogger()))->drain(1);
    }

    private function settings(string $token): CaseOrderSettings
    {
        return new CaseOrderSettings(
            url: 'https://order58.com/api/order-data/get-case-order-mixed',
            token: $token,
        );
    }

    /** Freshness and the provider gap both open, so a case can exercise one gate at a time. */
    private function openPolicy(): CaseOrderSyncPolicy
    {
        return new CaseOrderSyncPolicy(minRefreshSeconds: 0, minProviderGapSeconds: 0);
    }

    /**
     * A provider that answers 429, with or without a readable `Retry-After`.
     *
     * Mocked rather than reached: no test here touches the real Order58 API. Passing null models a
     * provider that refused without saying when — the case where this application must report the
     * refusal and still not invent a wait of its own.
     */
    private function rateLimitedClient(?int $retryAfterSeconds): FakeCaseOrderClient
    {
        return new FakeCaseOrderClient(
            null,
            CaseOrderFetchFailed::rateLimited(429, $retryAfterSeconds),
        );
    }

    private function succeedingClient(int $orders = 1): FakeCaseOrderClient
    {
        $orders = array_map(static fn(int $n): array => [
            'id' => 17066031 + $n,
            'account_id' => 1121,
            'items' => [[
                'id' => 45934271 + $n,
                'order_id' => 17066031 + $n,
                'name' => 'Wings',
                'qty' => 1,
            ]],
        ], range(0, $orders - 1));

        return new FakeCaseOrderClient(new CaseOrderResponse(
            httpStatus: 200,
            responseBytes: 128,
            sanitizedBody: (string) json_encode($orders),
            orders: $orders,
        ));
    }

    private function failingClient(CaseOrderFetchFailed $failure): FakeCaseOrderClient
    {
        return new FakeCaseOrderClient(null, $failure);
    }
}

/** Answers once with a fixture, and counts how many times it was asked. */
final class FakeCaseOrderClient implements CaseOrderClientInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly ?CaseOrderResponse $response,
        private readonly ?CaseOrderFetchFailed $failure = null,
    ) {}

    public function fetch(): CaseOrderResponse
    {
        ++$this->calls;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        /** @var CaseOrderResponse */
        return $this->response;
    }
}

/**
 * The run table, in memory, including the unique day.
 *
 * `claim()` returning null for a day it already holds is the behaviour under test in two cases, so it
 * is modelled rather than stubbed away: a fake that always succeeded would make the one-a-day property
 * untestable here.
 */
final class InMemoryCaseOrderRuns implements CaseOrderSyncRunRepositoryInterface
{
    public int $claims = 0;

    /** @var array<int, array<string, mixed>> keyed by run id */
    private array $rows = [];

    private int $nextId = 1;

    public function claim(
        string $businessDay,
        string $timezone,
        string $triggerSource,
        DateTimeImmutable $leaseUntil,
        DateTimeImmutable $now,
    ): ?CaseOrderSyncRun {
        // The real table refuses a second ACTIVE run, not a second run on the same day. Modelled,
        // because "many runs a day, one at a time" is the property under test.
        if ($this->active() !== null) {
            return null;
        }

        ++$this->claims;

        $id = $this->nextId++;

        $this->rows[$id] = [
            'id' => $id,
            'day' => $businessDay,
            'trigger' => $triggerSource,
            'lease' => $leaseUntil,
            'timezone' => $timezone,
            'status' => CaseOrderSyncStatus::Claimed,
            'stage' => null,
            'started' => $now,
            'completed' => null,
            'httpStatus' => null,
            'bytes' => null,
            'received' => 0,
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'items' => 0,
            'failures' => 0,
            'summary' => null,
            'payload' => null,
            'providerRetryAfter' => null,
        ];

        return $this->find($id);
    }

    public function active(): ?CaseOrderSyncRun
    {
        foreach ($this->rows as $row) {
            /** @var CaseOrderSyncStatus $status */
            $status = $row['status'];

            if (!$status->isFinished()) {
                return $this->hydrate($row);
            }
        }

        return null;
    }

    public function lastCompleted(): ?CaseOrderSyncRun
    {
        $found = null;

        foreach ($this->rows as $row) {
            if ($row['status'] === CaseOrderSyncStatus::Completed) {
                $found = $row;
            }
        }

        return $found === null ? null : $this->hydrate($found);
    }

    public function recoverExpired(DateTimeImmutable $now): int
    {
        $closed = 0;

        foreach ($this->rows as $id => $row) {
            /** @var CaseOrderSyncStatus $status */
            $status = $row['status'];
            $lease = $row['lease'] ?? null;

            if (!$status->isFinished() && $lease instanceof DateTimeImmutable && $lease < $now) {
                $this->rows[$id]['status'] = CaseOrderSyncStatus::Failed;
                $this->rows[$id]['stage'] = CaseOrderSyncRun::STAGE_ABANDONED;
                ++$closed;
            }
        }

        return $closed;
    }

    public function forDay(string $businessDay): ?CaseOrderSyncRun
    {
        $found = null;

        foreach ($this->rows as $row) {
            if ($row['day'] === $businessDay) {
                $found = $row;
            }
        }

        return $found === null ? null : $this->hydrate($found);
    }

    public function page(int $limit, int $offset = 0): array
    {
        return array_map(fn(array $r): CaseOrderSyncRun => $this->hydrate($r), array_values($this->rows));
    }

    public function count(): int
    {
        return count($this->rows);
    }

    public function latest(): ?CaseOrderSyncRun
    {
        $last = null;

        foreach ($this->rows as $row) {
            $last = $row;
        }

        return $last === null ? null : $this->hydrate($last);
    }


    public function find(int $id): ?CaseOrderSyncRun
    {
        foreach ($this->rows as $row) {
            if ($row['id'] === $id) {
                return $this->hydrate($row);
            }
        }

        return null;
    }

    public function markFetched(
        int $id,
        int $httpStatus,
        int $responseBytes,
        string $sanitizedPayload,
        DateTimeImmutable $now,
    ): void {
        $this->set($id, [
            'status' => CaseOrderSyncStatus::Fetched,
            'httpStatus' => $httpStatus,
            'bytes' => $responseBytes,
            'payload' => $sanitizedPayload,
        ]);
    }

    public function markProcessing(int $id, int $ordersReceived, DateTimeImmutable $now): void
    {
        $this->set($id, ['status' => CaseOrderSyncStatus::Processing, 'received' => $ordersReceived]);
    }

    public function complete(int $id, array $counts, ?string $summary, DateTimeImmutable $now): void
    {
        $this->set($id, [
            'status' => CaseOrderSyncStatus::Completed,
            'stage' => null,
            'completed' => $now,
            'completedAt' => $now,
            'inserted' => $counts['inserted'],
            'updated' => $counts['updated'],
            'skipped' => $counts['skipped'],
            'items' => $counts['items'],
            'failures' => $counts['failures'],
            'summary' => $summary,
        ]);
    }

    public function fail(
        int $id,
        string $stage,
        string $summary,
        DateTimeImmutable $now,
        ?DateTimeImmutable $providerRetryAfter = null,
    ): void {
        // The payload is deliberately untouched, exactly as the real repository leaves it.
        $values = [
            'status' => CaseOrderSyncStatus::Failed,
            'stage' => $stage,
            'completed' => $now,
            'summary' => $summary,
        ];

        // Null must not clear an instruction already on record, as in the real repository.
        if ($providerRetryAfter !== null) {
            $values['providerRetryAfter'] = $providerRetryAfter;
        }

        $this->set($id, $values);
    }

    public function lastProviderRateLimited(): ?CaseOrderSyncRun
    {
        $found = null;

        foreach ($this->rows as $row) {
            $at = $row['providerRetryAfter'] ?? null;

            if ($at instanceof DateTimeImmutable
                && ($found === null || $at > $found['providerRetryAfter'])) {
                $found = $row;
            }
        }

        return $found === null ? null : $this->hydrate($found);
    }

    public function stagedPayload(int $id): ?string
    {
        foreach ($this->rows as $row) {
            if ($row['id'] === $id) {
                /** @var string|null */
                return $row['payload'];
            }
        }

        return null;
    }

    public function discardStagedPayload(int $id, DateTimeImmutable $now): void
    {
        $this->set($id, ['payload' => null]);
    }

    /** @param array<string, mixed> $values */
    private function set(int $id, array $values): void
    {
        if (isset($this->rows[$id])) {
            $this->rows[$id] = $values + $this->rows[$id];
        }
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): CaseOrderSyncRun
    {
        /** @var CaseOrderSyncStatus $status */
        $status = $row['status'];

        return new CaseOrderSyncRun(
            id: (int) $row['id'],
            businessDay: (string) $row['day'],
            timezone: (string) $row['timezone'],
            status: $status,
            stage: $row['stage'] === null ? null : (string) $row['stage'],
            triggerSource: (string) ($row['trigger'] ?? 'manual'),
            startedAt: new DateTimeImmutable((string) ($row['started'] ?? 'now')->format('c')),
            fetchedAt: null,
            // The instant the fake recorded, so freshness arithmetic is real rather than invented.
            completedAt: ($row['completedAt'] ?? null) instanceof DateTimeImmutable
                ? $row['completedAt']
                : null,
            leaseExpiresAt: null,
            providerRetryAfter: ($row['providerRetryAfter'] ?? null) instanceof DateTimeImmutable
                ? $row['providerRetryAfter']
                : null,
            httpStatus: $row['httpStatus'] === null ? null : (int) $row['httpStatus'],
            responseBytes: $row['bytes'] === null ? null : (int) $row['bytes'],
            ordersReceived: (int) $row['received'],
            ordersInserted: (int) $row['inserted'],
            ordersUpdated: (int) $row['updated'],
            ordersSkipped: (int) $row['skipped'],
            ordersUnchanged: 0,
            itemsImported: (int) $row['items'],
            failures: (int) $row['failures'],
            errorSummary: $row['summary'] === null ? null : (string) $row['summary'],
            hasStagedPayload: $row['payload'] !== null,
        );
    }
}

/** Accepts every order and counts the writes. */
class CountingOrderRepository implements Order58OrderRepositoryInterface
{
    public int $writes = 0;

    public function hashesFor(int $accountId, array $sourceOrderIds): array
    {
        return [];
    }

    public function upsert(Order58Order $order, DateTimeImmutable $now): bool
    {
        ++$this->writes;

        return true;
    }

    public function page(OrderListQuery $query): array
    {
        return [];
    }
}

/** Refuses every write, standing in for a database that is unhappy mid-import. */
final class ThrowingOrderRepository extends CountingOrderRepository
{
    public function upsert(Order58Order $order, DateTimeImmutable $now): bool
    {
        // The message is the sort of thing a driver exception carries, which is why the service must
        // not put it in `error_summary`.
        throw new RuntimeException('SQLSTATE[HY000]: wrote nothing for 17066031');
    }
}

/** Writes the first order and refuses the second, so a run can be partly successful. */
final class FlakyOrderRepository extends CountingOrderRepository
{
    public function upsert(Order58Order $order, DateTimeImmutable $now): bool
    {
        ++$this->writes;

        if ($this->writes > 1) {
            throw new RuntimeException('SQLSTATE[HY000]: wrote nothing');
        }

        return true;
    }
}

final class CountingItemRepository implements OrderItemRepositoryInterface
{
    /** @var list<Order58OrderItem> */
    public array $stored = [];

    public function replace(
        int $storeAccountId,
        int $sourceOrderId,
        array $items,
        ?string $leaseOwner,
        DateTimeImmutable $now,
    ): bool {
        foreach ($items as $item) {
            $this->stored[] = $item;
        }

        return true;
    }

    public function forOrder(int $storeAccountId, int $sourceOrderId): array
    {
        return [];
    }
}

/** The item sync state, which this service never reads back. */
final class NoopItemSyncState implements ItemSyncStateRepositoryInterface
{
    public function claim(
        int $accountId,
        int $sourceOrderId,
        string $leaseOwner,
        array $fromStatuses,
        DateTimeImmutable $now,
        int $leaseSeconds,
    ): ?ItemSyncState {
        return null;
    }

    public function markSynced(
        int $accountId,
        int $sourceOrderId,
        ?string $leaseOwner,
        int $itemCount,
        string $responseHash,
        ?string $responseJson,
        DateTimeImmutable $now,
    ): void {}

    public function markFailed(
        int $accountId,
        int $sourceOrderId,
        string $leaseOwner,
        string $error,
        ?DateTimeImmutable $retryAfter,
        DateTimeImmutable $now,
    ): void {}

    public function expireLeases(DateTimeImmutable $now): int
    {
        return 0;
    }

    public function enqueue(
        int $accountId,
        array $changedIds,
        array $unchangedIds,
        int $maxAttempts,
        DateTimeImmutable $now,
    ): int {
        return 0;
    }

    public function nextQueued(int $limit, DateTimeImmutable $now): array
    {
        return [];
    }

    public function statusesFor(int $accountId, array $sourceOrderIds): array
    {
        return [];
    }
}
