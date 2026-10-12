<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58\Items;

use App\Order58\Application\Items\ItemSyncResult;
use App\Order58\Application\Items\OrderItemMapper;
use App\Order58\Application\Items\OriginalOrderItemSyncService;
use App\Order58\Client\Items\OrderItemClientInterface;
use App\Order58\Client\Items\OrderItemDataFailed;
use App\Order58\Client\Items\OrderItemSettings;
use App\Order58\Domain\Items\Order58OrderItem;
use App\Order58\Domain\Items\OrderItemRepositoryInterface;
use App\Shared\Domain\Clock\ClockInterface;
use App\Tests\Support\Fake\Order58\InMemoryItemSyncState;
use Codeception\Test\Unit;
use DateTimeImmutable;
use Psr\Log\NullLogger;

use function json_encode;
use function str_pad;

use const STR_PAD_LEFT;

/**
 * The one service the worker and both manual buttons call. **No test here reaches the network.**
 *
 * ## What is really being guarded
 *
 * Three promises that are easy to make and easy to break:
 *
 * 1. **A failure never costs the previous snapshot.** Items stay, `items_synced_at` stays, and the
 *    page can still say "showing items from then, the latest refresh failed".
 * 2. **A live lease means no API request at all** — not a request whose result is discarded.
 * 3. **"Nothing changed" still finishes the job.** No item rows are rewritten, but the lease is
 *    released and freshness moves, or the order would sit in PROCESSING forever.
 */
final class OriginalOrderItemSyncServiceTest extends Unit
{
    private const STORE = 1731;
    private const ORDER = 16800401;

    // ------------------------------------------------------------------ the happy paths

    public function testItemsAreFetchedMappedAndStored(): void
    {
        [$service, $state, $repo] = $this->service($this->response([$this->item('1'), $this->item('2')]));

        $outcome = $service->syncOrder(self::STORE, self::ORDER);

        $this->assertSame(ItemSyncResult::Synced, $outcome->result);
        $this->assertSame(2, $outcome->itemCount);
        $this->assertSame('SYNCED', $state->status);
        $this->assertCount(2, $repo->stored);
        $this->assertNotNull($state->syncedAt);
        $this->assertNull($state->owner, 'The lease must be released.');
    }

    /** An authoritative empty order: comparable, and emphatically not a failure. */
    public function testAnEmptyResponseIsRecordedAsEmptyNotFailed(): void
    {
        [$service, $state, $repo] = $this->service($this->response([]));

        $outcome = $service->syncOrder(self::STORE, self::ORDER);

        $this->assertSame(ItemSyncResult::Empty, $outcome->result);
        $this->assertSame('EMPTY', $state->status);
        $this->assertSame([], $repo->stored);
        $this->assertNotNull($state->syncedAt);
        $this->assertTrue($outcome->isSuccess());
    }

    /**
     * An unchanged response writes no item rows but must still settle the sync.
     *
     * "Zero writes" means zero ITEM writes. The lease, the attempt counter and the freshness stamp all
     * still have to move, or an order that stops changing would look stuck in PROCESSING.
     */
    public function testAnUnchangedResponseWritesNoItemsButStillFinishes(): void
    {
        [$service, $state, $repo] = $this->service($this->response([$this->item('1')]));

        $service->syncOrder(self::STORE, self::ORDER);
        $firstSyncedAt = $state->syncedAt;
        $repo->stored = ['sentinel'];   // if replace() runs again this is overwritten
        $repo->calls = 0;

        $outcome = $service->syncOrder(self::STORE, self::ORDER, manual: true);

        $this->assertSame(ItemSyncResult::Unchanged, $outcome->result);
        $this->assertSame(0, $repo->calls, 'An unchanged response must not rewrite item rows.');
        $this->assertSame(['sentinel'], $repo->stored);
        $this->assertSame('SYNCED', $state->status);
        $this->assertNull($state->owner, 'The lease must still be released.');
        $this->assertNotNull($state->syncedAt);
        $this->assertNotSame($firstSyncedAt, $state->syncedAt, 'Freshness must still move.');
    }

    // ------------------------------------------------------------------ failures keep the snapshot

    /**
     * @dataProvider failures
     */
    public function testAFailureKeepsEverythingAboutThePreviousSnapshot(OrderItemDataFailed $failure): void
    {
        [$service, $state, $repo] = $this->service($this->response([$this->item('1')]));

        $service->syncOrder(self::STORE, self::ORDER);
        $before = ['items' => $repo->stored, 'count' => $state->itemCount, 'at' => $state->syncedAt];

        $this->client->failWith = $failure;
        $outcome = $service->syncOrder(self::STORE, self::ORDER, manual: true);

        $this->assertSame(ItemSyncResult::Failed, $outcome->result);
        $this->assertSame('FAILED', $state->status);
        // The three things that make "stale snapshot kept" distinguishable from "never synced".
        $this->assertSame($before['items'], $repo->stored, 'Stored items must survive a failed refresh.');
        $this->assertSame($before['count'], $state->itemCount);
        $this->assertSame($before['at'], $state->syncedAt, 'items_synced_at must never be cleared.');
        $this->assertNotNull($state->error);
    }

    /** @return iterable<string, array{OrderItemDataFailed}> */
    public static function failures(): iterable
    {
        yield 'unreachable' => [OrderItemDataFailed::unreachable()];
        yield 'refused 500' => [OrderItemDataFailed::refused(500)];
        yield 'refused 429' => [OrderItemDataFailed::refused(429)];
        yield 'malformed' => [OrderItemDataFailed::malformed('the body was not valid JSON')];
        yield 'too large' => [OrderItemDataFailed::tooLarge(4194304)];
    }

    /** A never-synced order that fails has no snapshot — and the page must be able to tell. */
    public function testAFailureBeforeAnySuccessLeavesNoSnapshot(): void
    {
        [$service, $state] = $this->service($this->response([]));
        $this->client->failWith = OrderItemDataFailed::unreachable();

        $service->syncOrder(self::STORE, self::ORDER);

        $this->assertSame('FAILED', $state->status);
        $this->assertNull($state->syncedAt, 'Never synced: the discriminator must stay null.');
    }

    /** One unmappable line fails the whole response rather than silently dropping that line. */
    public function testOneUnmappableItemFailsTheWholeResponse(): void
    {
        [$service, $state, $repo] = $this->service($this->response([
            $this->item('1'),
            $this->item('2', ['order_id' => '99999999']),   // belongs to another order
        ]));

        $outcome = $service->syncOrder(self::STORE, self::ORDER);

        $this->assertSame(ItemSyncResult::Failed, $outcome->result);
        $this->assertSame([], $repo->stored, 'A partial set must never be written.');
        $this->assertSame('FAILED', $state->status);
    }

    // ------------------------------------------------------------------ concurrency

    /** A live lease means no request at all — not a request whose answer is thrown away. */
    public function testALiveLeaseBlocksTheRequestEntirely(): void
    {
        [$service, $state] = $this->service($this->response([$this->item('1')]));
        $state->status = 'PROCESSING';
        $state->owner = 'somebody-else';
        $state->leasedUntil = new DateTimeImmutable('2026-10-09 12:05:00');

        $outcome = $service->syncOrder(self::STORE, self::ORDER, manual: true);

        $this->assertSame(ItemSyncResult::Busy, $outcome->result);
        $this->assertSame(0, $this->client->calls, 'A held order must not be fetched.');
    }

    /** The worker only takes work it was given; a manual press takes almost anything. */
    public function testTheWorkerClaimsOnlyQueuedWhileManualJumpsTheQueue(): void
    {
        foreach (['PENDING', 'SYNCED', 'EMPTY', 'FAILED'] as $status) {
            [$worker, $state] = $this->service($this->response([$this->item('1')]));
            $state->status = $status;

            $this->assertSame(
                ItemSyncResult::Busy,
                $worker->syncOrder(self::STORE, self::ORDER)->result,
                "The worker must not claim a {$status} order.",
            );

            [$manual, $manualState] = $this->service($this->response([$this->item('1')]));
            $manualState->status = $status;

            $this->assertNotSame(
                ItemSyncResult::Busy,
                $manual->syncOrder(self::STORE, self::ORDER, manual: true)->result,
                "A manual refresh must be able to claim a {$status} order.",
            );
        }

        // And QUEUED — the case that makes the button jump the queue — works for both.
        [$manual, $state] = $this->service($this->response([$this->item('1')]));
        $state->status = 'QUEUED';
        $this->assertSame(
            ItemSyncResult::Synced,
            $manual->syncOrder(self::STORE, self::ORDER, manual: true)->result,
        );
    }

    /** Losing the lease mid-fetch must abandon the write, not force it through. */
    public function testALostLeaseAbandonsTheReplacement(): void
    {
        [$service, $state, $repo] = $this->service($this->response([$this->item('1')]));
        $repo->refuseOwnership = true;

        $outcome = $service->syncOrder(self::STORE, self::ORDER);

        $this->assertSame(ItemSyncResult::Busy, $outcome->result);
        $this->assertSame([], $repo->stored);
        // No terminal write either: the row belongs to whoever holds it now.
        $this->assertSame([], $state->writes);
    }

    /** Attempts increment at CLAIM, so a process killed mid-request still spends one. */
    public function testAttemptsIncrementAtClaim(): void
    {
        [$service, $state] = $this->service($this->response([$this->item('1')]));
        $this->client->failWith = OrderItemDataFailed::unreachable();

        $service->syncOrder(self::STORE, self::ORDER);

        $this->assertSame(1, $state->attempts);
        $this->assertNotNull($state->retryAfter, 'A retry is still due below the cap.');
    }

    /** Past the cap there is no automatic retry: a refusing provider is not fixed by asking again. */
    public function testRetryStopsAtTheAttemptCap(): void
    {
        [$service, $state] = $this->service($this->response([$this->item('1')]), maxAttempts: 2);
        $this->client->failWith = OrderItemDataFailed::unreachable();
        $state->attempts = 5;

        $service->syncOrder(self::STORE, self::ORDER);

        $this->assertSame('FAILED', $state->status);
        $this->assertNull($state->retryAfter, 'Exhausted attempts must leave it for a person.');
    }

    // ------------------------------------------------------------------ off is not broken

    /**
     * @dataProvider unavailableInstallations
     *
     * The correction this exists for: a click while the feature is off used to claim the order, spend
     * an attempt and record `FAILED` with a retry clock — a provider problem that never happened.
     * Nothing may be written, because nothing was attempted.
     */
    public function testAnUnavailableInstallationRecordsNothingAtAll(OrderItemSettings $settings): void
    {
        [$service, $state, $repo] = $this->service($this->response([$this->item('1')]), settings: $settings);
        $state->status = 'SYNCED';
        $state->attempts = 1;
        $state->syncedAt = new DateTimeImmutable('2026-10-08 09:00:00');

        $outcome = $service->syncOrder(self::STORE, self::ORDER, manual: true);

        $this->assertSame(ItemSyncResult::Unavailable, $outcome->result);
        $this->assertFalse($outcome->isSuccess(), 'Off must not read as a successful sync.');
        $this->assertSame(0, $this->client->calls, 'No connection may be opened.');
        $this->assertSame(0, $state->claims, 'The order must not even be claimed.');
        $this->assertSame('SYNCED', $state->status, 'The status must be untouched.');
        $this->assertSame(1, $state->attempts, 'An attempt must not be spent.');
        $this->assertSame('2026-10-08 09:00:00', $state->syncedAt->format('Y-m-d H:i:s'));
        $this->assertNull($state->retryAfter, 'No retry clock may be started.');
        $this->assertSame([], $repo->stored, 'No item row may be written.');
    }

    /** @return iterable<string, array{OrderItemSettings}> */
    public static function unavailableInstallations(): iterable
    {
        yield 'switched off' => [new OrderItemSettings('https://o58.test/list', 't', 'Bearer', 5, 25, 4096, false)];
        yield 'no url' => [new OrderItemSettings('', 'token', 'Bearer', 5, 25, 4096, true)];
        yield 'no token' => [new OrderItemSettings('https://o58.test/list', '', 'Bearer', 5, 25, 4096, true)];
        yield 'off and unset' => [new OrderItemSettings('', '', 'Bearer', 5, 25, 4096, false)];
    }

    /** The operator is told about the installation, and not given the setting names. */
    public function testTheUnavailableMessageNamesNoSettings(): void
    {
        [$service] = $this->service(
            $this->response([]),
            settings: new OrderItemSettings('', '', 'Bearer', 5, 25, 4096, false),
        );

        $outcome = $service->syncOrder(self::STORE, self::ORDER, manual: true);

        $this->assertSame(
            'Original Order Items synchronization is not enabled or configured.',
            $outcome->error,
        );
        $this->assertStringNotContainsString('ORDER58_', (string) $outcome->error);
    }

    /** An order never synced stays never synced, rather than becoming a failed one. */
    public function testAnUnavailableInstallationLeavesAPendingOrderPending(): void
    {
        [$service, $state] = $this->service(
            $this->response([$this->item('1')]),
            settings: new OrderItemSettings('', '', 'Bearer', 5, 25, 4096, false),
        );
        $state->status = 'PENDING';

        $service->syncOrder(self::STORE, self::ORDER, manual: true);

        $this->assertSame('PENDING', $state->status);
        $this->assertNull($state->syncedAt);
        $this->assertSame(0, $state->attempts);
    }

    /** The background drainer gets the same answer, so the worker cannot mark a backlog failed either. */
    public function testTheWorkerAlsoGetsUnavailableWithoutClaiming(): void
    {
        [$service, $state] = $this->service(
            $this->response([$this->item('1')]),
            settings: new OrderItemSettings('', '', 'Bearer', 5, 25, 4096, false),
        );
        $state->status = 'QUEUED';

        $outcome = $service->syncOrder(self::STORE, self::ORDER);

        $this->assertSame(ItemSyncResult::Unavailable, $outcome->result);
        $this->assertSame('QUEUED', $state->status, 'The order must stay queued for when it is enabled.');
        $this->assertSame(0, $state->claims);
    }

    // ------------------------------------------------------------------ helpers

    private FakeItemClient $client;
    private \App\Tests\Support\Fake\Order58\RecordingItemSyncBatches $batches;

    /**
     * @param array{items: list<array<array-key, mixed>>, body: string} $response
     * @return array{OriginalOrderItemSyncService, InMemoryItemSyncState, FakeItemRepository}
     */
    private function service(array $response, int $maxAttempts = 3, ?OrderItemSettings $settings = null): array
    {
        $this->client = new FakeItemClient($response);
        $this->batches = new \App\Tests\Support\Fake\Order58\RecordingItemSyncBatches();
        $state = new InMemoryItemSyncState();
        $repo = new FakeItemRepository();

        $clock = new class implements ClockInterface {
            private int $tick = 0;

            public function now(): DateTimeImmutable
            {
                // Advances, so "freshness moved" is observable between two syncs.
                return new DateTimeImmutable('2026-10-09 12:00:' . str_pad((string) (++$this->tick), 2, '0', STR_PAD_LEFT));
            }
        };

        return [
            new OriginalOrderItemSyncService(
                $this->client,
                $repo,
                $state,
                new OrderItemMapper(),
                $settings ?? self::configured(),
                $this->batches,
                $clock,
                new NullLogger(),
                120,
                $maxAttempts,
            ),
            $state,
            $repo,
        ];
    }

    /** A configured, switched-on installation — what every other test here assumes. */
    private static function configured(): OrderItemSettings
    {
        return new OrderItemSettings(
            'https://order58.test/api/order-item-data/list',
            'test-token',
            'Bearer',
            5,
            25,
            4 * 1024 * 1024,
            true,
        );
    }

    /**
     * @param list<array<array-key, mixed>> $items
     * @return array{items: list<array<array-key, mixed>>, body: string}
     */
    private function response(array $items): array
    {
        return ['items' => $items, 'body' => (string) json_encode(['OrderItems' => $items])];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function item(string $id, array $overrides = []): array
    {
        return $overrides + [
            'id' => $id,
            'name' => '[F7]Wings',
            'qty' => '1',
            'price' => '11.35000',
            'order_id' => (string) self::ORDER,
            'account_id' => '0',
            'data' => '{"product":{"name":"Wings","sn":"F7"},"additionals":null,"combinations":null}',
        ];
    }
}

/** Answers with a canned response, or throws. Counts calls so "made no request" is assertable. */
final class FakeItemClient implements OrderItemClientInterface
{
    public int $calls = 0;
    public ?OrderItemDataFailed $failWith = null;

    /** @param array{items: list<array<array-key, mixed>>, body: string} $response */
    public function __construct(private readonly array $response) {}

    public function listItems(int $accountId, int $sourceOrderId): array
    {
        ++$this->calls;

        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        return $this->response;
    }
}

/** Stores in memory, and can refuse ownership the way the real transaction does. */
final class FakeItemRepository implements OrderItemRepositoryInterface
{
    /** @var list<mixed> */
    public array $stored = [];
    public int $calls = 0;
    public bool $refuseOwnership = false;

    public function replace(
        int $storeAccountId,
        int $sourceOrderId,
        array $items,
        string $leaseOwner,
        DateTimeImmutable $now,
    ): bool {
        ++$this->calls;

        if ($this->refuseOwnership) {
            // Nothing deleted, nothing inserted — exactly what the real transaction does.
            return false;
        }

        $this->stored = $items;

        return true;
    }

    public function forOrder(int $storeAccountId, int $sourceOrderId): array
    {
        /** @var list<Order58OrderItem> */
        return $this->stored;
    }
}
