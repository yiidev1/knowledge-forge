<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\KnowledgeBase\Domain\KnowledgeBaseSourceRepositoryInterface;
use App\Order58\Application\EnsureStoreKnowledgeBaseService;
use App\Order58\Application\Formatter\Order58StoreProfileFormatter;
use App\Order58\Application\Mapper\StoreMapper;
use App\Order58\Application\Order58SyncParams;
use App\Order58\Application\Sync\StoresSyncHandler;
use App\Order58\Application\SyncDocumentService;
use App\Order58\Contract\Dto\Order58Account;
use App\Order58\Contract\Dto\Order58Page;
use App\Order58\Contract\Dto\Order58Pagination;
use App\Order58\Contract\Order58ClientInterface;
use App\Order58\Domain\Order58StoreRepositoryInterface;
use App\Order58\Domain\Order58SyncType;
use App\Order58\Domain\StoreMirror;
use App\Order58\Domain\SyncProgress;
use App\Order58\Domain\SyncRun;
use App\Order58\Domain\SyncRunRepositoryInterface;
use App\Order58\Domain\SyncRunStatus;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use ReflectionClass;

/**
 * The decision the whole change turns on: a store whose `_sync_hash` did not move still gets its host
 * refreshed. **No test here reaches a network or a database.**
 *
 * ## Why the knowledge collaborators are built without constructors
 *
 * `EnsureStoreKnowledgeBaseService`, `SyncDocumentService` and the profile formatter are `final`, so they
 * cannot be mocked, and building them for real would drag in half the container for a test about one
 * `if`. They are instantiated **without their constructors** instead, which makes them unusable — any
 * call lands on an uninitialised readonly property and throws.
 *
 * That is the point rather than a compromise. The claim under test is that a host-only change regenerates
 * *nothing*, and this makes the claim self-enforcing: if the unchanged-hash path ever starts ensuring a
 * knowledge base or upserting a document, this test does not quietly keep passing — it dies.
 */
final class StoreHostSyncDecisionTest extends Unit
{
    private const STORE_ID = 61;
    private const HASH = 'unchanged-hash';
    private const STORED_HOST = 'old.order58.com';
    private const NEW_HOST = '0000000004.17ip.com';

    /** Case C — the one the client asked for. Hash identical, host moved: the host is written. */
    public function testAHostOnlyChangeIsWrittenEvenWhenTheHashIsUnchanged(): void
    {
        $stores = $this->storeRepository(self::HASH);
        $this->syncOnce($stores, ['host' => self::NEW_HOST]);

        $this->assertSame([[self::STORE_ID, self::NEW_HOST]], $stores->hostWrites);
    }

    /** Case B — nothing moved. The narrow write is still attempted; the repository is what declines it. */
    public function testAnUnchangedStoreIsStillCountedUnchanged(): void
    {
        $stores = $this->storeRepository(self::HASH);
        $progress = $this->syncOnce($stores, ['host' => self::STORED_HOST]);

        $this->assertSame(1, $progress->unchanged);
        $this->assertSame(0, $progress->created);
        $this->assertSame(0, $progress->updated);
    }

    /** An unchanged store is never rewritten wholesale — the cheap path stays cheap. */
    public function testAnUnchangedStoreIsNeverSaved(): void
    {
        $stores = $this->storeRepository(self::HASH);
        $this->syncOnce($stores, ['host' => self::NEW_HOST]);

        $this->assertSame([], $stores->saved, 'A host-only change must not rewrite the whole row.');
        $this->assertSame([self::STORE_ID], $stores->markedSeen);
    }

    /**
     * A response that mentions no host passes null down, and the repository leaves the stored value.
     *
     * Asserted at the boundary rather than in the database: what matters here is that the handler does
     * not invent a value or skip the call, and `null` is how it says "nothing usable arrived".
     */
    public function testAnAbsentHostReachesTheRepositoryAsNull(): void
    {
        $stores = $this->storeRepository(self::HASH);
        $this->syncOnce($stores, []);

        $this->assertSame([[self::STORE_ID, null]], $stores->hostWrites);
    }

    /** A malformed host is declined by the normaliser, not passed through to the column. */
    public function testAMalformedHostReachesTheRepositoryAsNull(): void
    {
        $stores = $this->storeRepository(self::HASH);
        $this->syncOnce($stores, ['host' => 'https://shop.example.com/menu']);

        $this->assertSame([[self::STORE_ID, null]], $stores->hostWrites);
    }

    // ------------------------------------------------------------------ harness

    /**
     * @param array<string, mixed> $extraRaw
     */
    private function syncOnce(object $stores, array $extraRaw): SyncProgress
    {
        $account = new Order58Account(
            id: self::STORE_ID,
            name: '0000000004 Test',
            company: '0004',
            active: false,
            activeKnown: true,
            syncHash: self::HASH,
            raw: ['id' => self::STORE_ID, 'name' => '0000000004 Test', '_sync_hash' => self::HASH] + $extraRaw,
        );

        $client = $this->createStub(Order58ClientInterface::class);
        $client->method('listAccounts')->willReturn(
            new Order58Page([$account], new Order58Pagination(page: 1, perPage: 50, total: 1, totalPages: 1)),
        );

        $handler = new StoresSyncHandler(
            $client,
            $stores,
            $this->createStub(KnowledgeBaseSourceRepositoryInterface::class),
            $this->unusable(EnsureStoreKnowledgeBaseService::class),
            new StoreMapper(),
            $this->unusable(Order58StoreProfileFormatter::class),
            $this->unusable(SyncDocumentService::class),
            $this->createStub(SyncRunRepositoryInterface::class),
            new Order58SyncParams(pageSize: 50, maxAttempts: 3, pagesPerRun: 5),
        );

        $run = $this->syncRun();
        $handler->handle($run, new DateTimeImmutable('2026-10-02 00:00:00', new DateTimeZone('UTC')));

        return $run->progress();
    }

    /**
     * An instance that exists but cannot be used, so touching it fails loudly.
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function unusable(string $class): object
    {
        /** @var T */
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    private function syncRun(): SyncRun
    {
        $at = new DateTimeImmutable('2026-10-02 00:00:00', new DateTimeZone('UTC'));

        return new SyncRun(
            1,
            Order58SyncType::Stores,
            null,
            SyncRunStatus::Running,
            1,
            null,
            new SyncProgress(),
            $at,
            null,
            null,
            null,
            $at,
            $at,
        );
    }

    /** Records what the handler asked for, and answers the one question it asks. */
    private function storeRepository(string $storedHash): object
    {
        return new class ($storedHash) implements Order58StoreRepositoryInterface {
            /** @var list<array{0: int, 1: string|null}> */
            public array $hostWrites = [];
            /** @var list<int> */
            public array $markedSeen = [];
            /** @var list<int> */
            public array $saved = [];

            public function __construct(private string $storedHash) {}

            public function findSyncHash(int $sourceId): ?string
            {
                return $this->storedHash;
            }

            public function updateHostIfChanged(int $sourceId, ?string $host, DateTimeImmutable $now): bool
            {
                $this->hostWrites[] = [$sourceId, $host];

                return $host !== null;
            }

            public function markSeen(int $sourceId, int $runId, DateTimeImmutable $now): void
            {
                $this->markedSeen[] = $sourceId;
            }

            public function save(StoreMirror $store, int $runId, DateTimeImmutable $now): void
            {
                $this->saved[] = $store->sourceId;
            }

            public function deactivateNotSeen(int $runId, DateTimeImmutable $now): array
            {
                return [];
            }

            public function findBySourceId(int $sourceId): ?StoreMirror
            {
                return null;
            }

            public function allMirrors(): array
            {
                return [];
            }

            public function setActive(int $sourceId, bool $active, DateTimeImmutable $now): void {}

            public function countAll(): int
            {
                return 0;
            }

            public function countActive(): int
            {
                return 0;
            }
        };
    }
}
