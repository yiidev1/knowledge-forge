<?php

declare(strict_types=1);

namespace App\Tests\Integration\Order58;

use App\Integration\Order58Recording\RecordingChannel;
use App\Order58\Domain\Order58ImportStatus;
use App\Order58\Infrastructure\DbCallImportRepository;
use App\Shared\Infrastructure\Db\DbDateTime;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

/**
 * The two guarantees that have to come from the database rather than from PHP.
 *
 * 1. **The same call cannot be imported twice.** The provider's call list returns the same call on every
 *    poll, and an administrator pressing Sync twice is ordinary. A check-then-insert would be a race two
 *    requests in the same second could lose, and losing it costs a second paid transcription — so the
 *    guard is a unique key, and only a real database can prove a unique key holds.
 * 2. **Two workers cannot claim one item.** The claim is a conditional UPDATE whose affected-row count is
 *    the lock token; a test double would assert the method was called, not that the row was taken once.
 *
 * Every row written here is removed in `_after`, addressed by a store id far outside the mirrored range.
 */
final class CallImportRepositoryTest extends Unit
{
    private const STORE = 987655100;
    private const OTHER_STORE = 987655101;

    private ConnectionInterface $connection;
    private DbCallImportRepository $repository;
    private DateTimeImmutable $now;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->repository = new DbCallImportRepository($this->connection);
        $this->now = new DateTimeImmutable('2026-09-24 06:09:16');
        $this->cleanup();
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    // ------------------------------------------------------------------ the duplicate guard

    /** The same call queued twice creates one set of rows, and says so. */
    public function testTheSameCallCannotBeQueuedTwice(): void
    {
        $first = $this->batch();
        $created = $this->queue($first, '22487129');
        self::assertSame(3, $created, 'Three channels, three rows.');

        // A second Sync click, a second batch, the same call.
        $second = $this->batch();
        $again = $this->queue($second, '22487129');

        self::assertSame(0, $again, 'Nothing new: the identity already exists.');
        self::assertSame(3, $this->countItems(), 'And no duplicate rows were written.');
    }

    /**
     * The same call session id under a different store is a different import.
     *
     * The account is part of the key because the provider has not confirmed whether a call session id is
     * unique across accounts. If it turns out to be, this costs nothing; if it is not, leaving the
     * account out would make one store's import silently suppress another's.
     */
    public function testTheSameSessionIdUnderAnotherStoreIsItsOwnImport(): void
    {
        $this->queue($this->batch(), '22487129');
        $this->queue($this->batch(self::OTHER_STORE), '22487129', self::OTHER_STORE);

        self::assertSame(6, $this->countItems(), 'Two stores, three channels each.');
    }

    /** A partially-queued call completes on the next attempt rather than being refused wholesale. */
    public function testOnlyTheChannelsThatAreMissingAreAdded(): void
    {
        $batch = $this->batch();
        self::assertSame(
            1,
            $this->repository->queueCall(
                $batch,
                self::STORE,
                '22487129',
                '2026-09-24 06:09:16',
                '2026-09-24',
                '16547451',
                [RecordingChannel::Mixed],
                $this->now,
            ),
        );

        // Now all three are asked for; the mixed one already exists.
        self::assertSame(2, $this->queue($this->batch(), '22487129'));
        self::assertSame(3, $this->countItems());
    }

    // ------------------------------------------------------------------ the claim

    /** One claim wins; a second finds nothing left to take. */
    public function testAnItemIsClaimedExactlyOnce(): void
    {
        $this->repository->queueCall(
            $this->batch(),
            self::STORE,
            '22487129',
            '2026-09-24 06:09:16',
            '2026-09-24',
            '16547451',
            [RecordingChannel::Mixed],
            $this->now,
        );

        $first = $this->repository->claimNext($this->now);
        self::assertNotNull($first);
        self::assertSame(Order58ImportStatus::Fetching, $first->status);
        self::assertSame('22487129', $first->callSessionId);

        self::assertNull($this->repository->claimNext($this->now), 'The only item is already taken.');
    }

    /** The claimed item carries the batch's options, so the worker needs no second read. */
    public function testAClaimedItemCarriesTheBatchsSnapshot(): void
    {
        $this->queue($this->batch(provider: 'DEEPGRAM', ai: true, company: 'KONG'), '22487129');

        $item = $this->repository->claimNext($this->now);

        self::assertNotNull($item);
        self::assertSame('KONG', $item->recordingCompany, 'The company was snapshotted with the batch.');
        self::assertSame('DEEPGRAM', $item->provider);
        self::assertTrue($item->generateAiAudio);
        self::assertSame('2026-09-24', $item->callDate, 'The date came from the call, not from today.');
    }

    /**
     * A batch created after the mirror changed keeps the code it was created with.
     *
     * The store sync rewrites `order58_stores` wholesale. Re-reading the company when the worker runs
     * would let a sync change what an already-queued batch fetches — silently, and for a recording that
     * may already be half-imported.
     */
    public function testAQueuedBatchKeepsItsCompanyEvenIfTheStoreChanges(): void
    {
        // A mirrored store, and a batch created while its code was KONG.
        $this->connection->createCommand()->insert('{{%order58_stores}}', [
            'source_id' => self::STORE,
            'name' => '__kf_call_import_store__',
            'company' => 'KONG',
            'active' => 1,
            'snapshot_json' => '{}',
            'sync_hash' => str_repeat('0', 64),
            'synced_at' => DbDateTime::format($this->now),
            'created_at' => DbDateTime::format($this->now),
            'updated_at' => DbDateTime::format($this->now),
        ])->execute();

        $this->queue($this->batch(company: 'KONG'), '22487129');

        // The next store sync rewrites the mirror. Nothing already queued may follow it — a different
        // value here is the failure this test exists to catch, and it would be silent.
        $this->connection->createCommand()->update(
            '{{%order58_stores}}',
            ['company' => 'CHANGED'],
            ['source_id' => self::STORE],
        )->execute();

        $item = $this->repository->claimNext($this->now);

        self::assertNotNull($item);
        self::assertSame('KONG', $item->recordingCompany, 'The snapshot, not the store row.');
    }

    /** A worker killed mid-fetch returns its item rather than stranding it. */
    public function testAStrandedItemIsRecovered(): void
    {
        $this->queue($this->batch(), '22487129');
        $claimed = $this->repository->claimNext($this->now);
        self::assertNotNull($claimed);

        $later = $this->now->modify('+30 minutes');
        self::assertSame(1, $this->repository->recoverStuck($this->now->modify('+15 minutes'), $later));

        $again = $this->repository->claimNext($later);
        self::assertNotNull($again, 'It is claimable again.');
        self::assertSame($claimed->id, $again->id);
    }

    // ------------------------------------------------------------------ outcomes and retry

    /** Only a failed item may be retried; anything else is left exactly as it is. */
    public function testRetryAppliesOnlyToAFailure(): void
    {
        $this->queue($this->batch(), '22487129');
        $item = $this->repository->claimNext($this->now);
        self::assertNotNull($item);

        $this->repository->markSettled(
            $item->id,
            Order58ImportStatus::NotAvailable,
            'not-found',
            'No such recording.',
            null,
            null,
            $this->now,
        );

        self::assertFalse(
            $this->repository->retry($item->id, $this->now),
            'A channel this merchant does not produce is nothing to retry.',
        );

        $this->repository->markSettled($item->id, Order58ImportStatus::Failed, 'server-error', 'Boom.', null, null, $this->now);

        self::assertTrue($this->repository->retry($item->id, $this->now));

        $retried = $this->repository->findItem($item->id);
        self::assertNotNull($retried);
        self::assertSame(Order58ImportStatus::Pending, $retried->status);
        self::assertSame(0, $retried->attempts, 'A deliberate retry starts the count again.');
        self::assertNull($retried->errorMessage);
    }

    /** A requeue counts the attempt and holds the item back until its next slot. */
    public function testARequeueCountsTheAttemptAndDefersIt(): void
    {
        // One channel only: with three queued, a second claim would simply take a sibling and prove
        // nothing about whether the deferred one was held back.
        $this->repository->queueCall(
            $this->batch(),
            self::STORE,
            '22487129',
            '2026-09-24 06:09:16',
            '2026-09-24',
            '16547451',
            [RecordingChannel::Mixed],
            $this->now,
        );
        $item = $this->repository->claimNext($this->now);
        self::assertNotNull($item);

        $this->repository->requeue($item->id, $this->now->modify('+60 seconds'), 'rate-limited', 'Slow down.', $this->now);

        self::assertNull($this->repository->claimNext($this->now), 'Not yet due.');

        $due = $this->repository->claimNext($this->now->modify('+61 seconds'));
        self::assertNotNull($due);
        self::assertSame(1, $due->attempts);
    }

    /** The page's status column reads every channel of the calls it is about to list. */
    public function testStatusesAreReportedPerCall(): void
    {
        $this->queue($this->batch(), '22487129');
        $this->queue($this->batch(), '22487119');

        $statuses = $this->repository->statusesFor(self::STORE, ['22487129', '22487119', '22487109']);

        self::assertCount(3, $statuses['22487129']);
        self::assertCount(3, $statuses['22487119']);
        self::assertArrayNotHasKey('22487109', $statuses, 'A call never queued has no entry at all.');
    }

    // ------------------------------------------------------------------ harness

    private function batch(
        int $store = self::STORE,
        string $provider = 'WHISPER',
        bool $ai = false,
        string $company = 'WGEU',
    ): int {
        return $this->repository->createBatch($store, 'MANUAL', null, $provider, $ai, $company, $this->now);
    }

    private function queue(int $batchId, string $sessionId, int $store = self::STORE): int
    {
        return $this->repository->queueCall(
            $batchId,
            $store,
            $sessionId,
            '2026-09-24 06:09:16',
            '2026-09-24',
            '16547451',
            RecordingChannel::all(),
            $this->now,
        );
    }

    private function countItems(): int
    {
        return (int) (new Query($this->connection))
            ->from('{{%order58_call_imports}}')
            ->where(['store_source_id' => [self::STORE, self::OTHER_STORE]])
            ->count();
    }

    private function cleanup(): void
    {
        // Children first: the foreign key is RESTRICT.
        $this->connection->createCommand()->delete(
            '{{%order58_call_imports}}',
            ['store_source_id' => [self::STORE, self::OTHER_STORE]],
        )->execute();
        $this->connection->createCommand()->delete(
            '{{%order58_call_import_batches}}',
            ['store_source_id' => [self::STORE, self::OTHER_STORE]],
        )->execute();
        $this->connection->createCommand()->delete(
            '{{%order58_stores}}',
            ['source_id' => [self::STORE, self::OTHER_STORE]],
        )->execute();
    }
}
