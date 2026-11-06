<?php

declare(strict_types=1);

namespace App\Tests\Integration\Order58;

use App\AudioToText\Domain\OrderGroupFilter;
use App\AudioToText\Domain\OrderListingMode;
use App\AudioToText\Domain\StoreOrderGroup;
use App\AudioToText\Infrastructure\DbCaseRecordingReader;
use App\AudioToText\Infrastructure\DbStoreOrderGroupRepository;
use App\AudioToText\Infrastructure\Tts\DbTtsRenditionRepository;
use App\Order58\Infrastructure\DbCallRecordingRepository;
use App\Order58\Infrastructure\DbRecordingDurationRepository;
use App\Order58\Infrastructure\DbStoreAudioCounts;
use App\Order58\Infrastructure\DbStoreTopicAssignmentCounts;
use App\Shared\Domain\Clock\SystemClock;
use App\Shared\Order58\RecordingDurationLimit;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function array_map;
use function gmdate;
use function hash;
use function sort;
use function str_pad;

use const STR_PAD_LEFT;

/**
 * The recording-duration rule against the real tables: which orders the store page lists, which
 * recordings a row offers, what the picker cards count, and how a duration is stored once.
 *
 * Store A's orders cover every case; store B has one order, hidden by the rule, so B disappears from the
 * picker's store lists exactly when the rule is on. Limit for 84.48 = 1013.76 s.
 */
final class RecordingDurationFilterTest extends Unit
{
    private const A = 987655967;
    private const B = 987655968;

    /** order => [total_amount, list of recording durations (null = unmeasured)] */
    private const ORDERS = [
        16910001 => ['84.48000', [900.0]],             // within: listed
        16910002 => ['84.48000', [1013.76]],           // exactly the limit: listed
        16910003 => ['84.48000', [1014.0]],            // over: hidden
        16910004 => ['84.48000', [null]],              // not measured yet: listed
        16910005 => [null, [5000.0]],                  // no total: listed
        16910006 => ['0.00000', [5000.0]],             // zero total: listed
        16910007 => ['84.48000', [1100.0, 600.0]],     // one over, one within: listed, offers the 600
        16910008 => ['84.48000', [1100.0, 1200.0]],    // every recording over: hidden
        16910009 => ['84.48000', []],                  // no recording: listed
    ];
    private const B_ORDER = 16910101;

    private const TOPIC = 'ZZ Duration Rule Topic';

    private ConnectionInterface $connection;

    /** @var array<int, list<int>> order => recording ids */
    private array $recordingIds = [];

    private int $topicId = 0;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        $this->store(self::A);
        $this->store(self::B);

        foreach (self::ORDERS as $order => [$total, $durations]) {
            $this->order(self::A, $order, $total);
            $this->recordings(self::A, $order, $durations);
        }

        $this->order(self::B, self::B_ORDER, '84.48000');
        $this->recordings(self::B, self::B_ORDER, [2000.0]);
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    // ---------------------------------------------------------------- the store page

    public function testTheStorePageListsAllButTheOrdersWhoseEveryRecordingIsOver(): void
    {
        $filter = OrderGroupFilter::everything()->withRecordingDurationLimit();

        self::assertSame(7, $this->listing($this->on())->countFor(self::A, $filter));
        self::assertSame(
            [16910001, 16910002, 16910004, 16910005, 16910006, 16910007, 16910009],
            $this->listedOrders($this->listing($this->on()), $filter),
        );
    }

    /** The SQL and the PHP statement of the rule agree on every fixture order. */
    public function testTheSqlAndThePhpRuleAgree(): void
    {
        $rule = $this->on();
        $expected = [];

        foreach (self::ORDERS as $order => [$total, $durations]) {
            if ($rule->keepsOrder($durations, $total)) {
                $expected[] = $order;
            }
        }

        self::assertSame($expected, $this->listedOrders($this->listing($rule), OrderGroupFilter::everything()->withRecordingDurationLimit()));
    }

    public function testSwitchedOffNothingIsHidden(): void
    {
        $filter = OrderGroupFilter::everything()->withRecordingDurationLimit();

        self::assertSame(9, $this->listing(new RecordingDurationLimit(false))->countFor(self::A, $filter));
    }

    /** A surface that does not ask for the rule is unchanged even while the rule is on. */
    public function testOnlyASurfaceThatAsksIsNarrowed(): void
    {
        self::assertSame(9, $this->listing($this->on())->countFor(self::A, OrderGroupFilter::everything()));
        self::assertSame(9, $this->listing($this->on())->countFor(self::A, null));
    }

    /** The topic filter and the rule compose: the topic narrows first, the rule removes what is over. */
    public function testTheTopicFilterStillWorks(): void
    {
        $this->assignTopic(16910001);
        $this->assignTopic(16910003);
        $filter = OrderGroupFilter::forTopics([$this->topicId])?->withRecordingDurationLimit();

        self::assertNotNull($filter);
        self::assertSame([16910001], $this->listedOrders($this->listing($this->on()), $filter));
    }

    /** A listed order offers only the recordings within its limit. */
    public function testARowOffersOnlyItsRecordingsWithinTheLimit(): void
    {
        $reader = new DbCaseRecordingReader($this->connection, $this->on());

        $limited = $reader->forOrders(self::A, ['16910007'], true);
        self::assertCount(1, $limited['16910007']);
        self::assertSame($this->recordingIds[16910007][1], $limited['16910007'][0]->recordingId);

        self::assertCount(2, $reader->forOrders(self::A, ['16910007'], false)['16910007'], 'not asked: unchanged');
        // A recording whose order has no total, or a zero one, is never left out — not even by SQL's NULL.
        self::assertCount(1, $reader->forOrders(self::A, ['16910005'], true)['16910005'] ?? [], 'no total');
        self::assertCount(1, $reader->forOrders(self::A, ['16910006'], true)['16910006'] ?? [], 'zero total');
        self::assertCount(1, $reader->forOrders(self::A, ['16910004'], true)['16910004'] ?? [], 'not measured');
        self::assertArrayNotHasKey('16910003', $reader->forOrders(self::A, ['16910003'], true), 'over');
        self::assertCount(2, (new DbCaseRecordingReader($this->connection))->forOrders(self::A, ['16910007'], true)['16910007'], 'rule off: unchanged');
    }

    // ---------------------------------------------------------------- the picker cards

    public function testThePickerCountsWhatTheStorePageLists(): void
    {
        $counts = new DbStoreAudioCounts($this->connection, true, $this->on());

        self::assertSame(7, $counts->countsFor([self::A, self::B], true)[self::A]->orders);
        self::assertArrayNotHasKey(self::B, $counts->countsFor([self::A, self::B], true), 'B lists nothing');

        $recordings = $counts->caseRecordingCountsFor([self::A, self::B]);
        // Orders with a recording the page would offer: 1, 2, 4, 5, 6 and 7 (its 600 s one).
        self::assertSame(6, $recordings[self::A]->orders);
        self::assertSame(6, $recordings[self::A]->files);
        self::assertArrayNotHasKey(self::B, $recordings);
    }

    public function testAStoreWithNothingLeftDropsOutOfThePickerLists(): void
    {
        $on = new DbStoreAudioCounts($this->connection, true, $this->on());
        $off = new DbStoreAudioCounts($this->connection, true);

        foreach ([
            'case orders' => static fn(DbStoreAudioCounts $c): array => $c->storesWithCaseOrders(),
            'case recordings' => static fn(DbStoreAudioCounts $c): array => $c->storesWithCaseRecordings(),
        ] as $list => $read) {
            self::assertContains(self::A, $read($on), $list);
            self::assertNotContains(self::B, $read($on), $list);
            self::assertContains(self::B, $read($off), $list . ', rule off');
        }
    }

    public function testTopicCountsLeaveOutHiddenOrders(): void
    {
        $this->assignTopic(16910001);
        $this->assignTopic(16910003);

        self::assertSame(1, (new DbStoreTopicAssignmentCounts($this->connection, $this->on()))->assignedOrdersFor([self::A])[self::A]);
        self::assertSame(2, (new DbStoreTopicAssignmentCounts($this->connection))->assignedOrdersFor([self::A])[self::A]);
    }

    // ---------------------------------------------------------------- storing a duration once

    public function testADurationIsStoredOnceAndNeverOverwritten(): void
    {
        $repository = new DbRecordingDurationRepository($this->connection);
        $id = $this->recordingIds[16910004][0];
        $now = new DateTimeImmutable('2026-11-06 10:00:00');

        self::assertTrue($repository->record($id, 321.0625, $now));
        self::assertFalse($repository->record($id, 999.0, $now), 'a second write changes nothing');
        self::assertSame('321.0625000', $this->durationOf($id));

        $location = (new DbCallRecordingRepository($this->connection, new SystemClock()))->locate(self::A, 16910004, $id);
        self::assertSame(321.0625, $location?->durationSeconds, 'the page reads it back');
    }

    public function testOnlyOneRunCanClaimARecording(): void
    {
        $repository = new DbRecordingDurationRepository($this->connection);
        $id = $this->recordingIds[16910004][0];
        $now = new DateTimeImmutable('2026-11-06 10:00:00');
        $lease = new DateTimeImmutable('2026-11-06 10:10:00');

        self::assertTrue($repository->claim($id, $now, $lease));
        self::assertFalse($repository->claim($id, $now, $lease), 'held');
        self::assertTrue($repository->claim($id, $lease, new DateTimeImmutable('2026-11-06 10:20:00')), 'free again after the lease');
    }

    public function testFailuresBackOffAndRetriesRunOut(): void
    {
        $repository = new DbRecordingDurationRepository($this->connection);
        $id = $this->recordingIds[16910004][0];
        $now = new DateTimeImmutable('2026-11-06 10:00:00');

        $repository->failed($id, 'upstream-error', new DateTimeImmutable('2026-11-06 10:15:00'), true);
        self::assertNotContains($id, $this->dueIds($repository, $now), 'not before its retry time');
        self::assertContains($id, $this->dueIds($repository, new DateTimeImmutable('2026-11-06 10:15:00')));

        $repository->abandon($id, 'not-found', 5);
        self::assertNotContains($id, $this->dueIds($repository, new DateTimeImmutable('2026-12-01')));
        self::assertGreaterThanOrEqual(1, $repository->resetExhausted(5));
        self::assertContains($id, $this->dueIds($repository, $now), 'reset: due again');
    }

    // ---------------------------------------------------------------- helpers

    private function on(): RecordingDurationLimit
    {
        return new RecordingDurationLimit(true, 2.0, 10.0);
    }

    private function listing(RecordingDurationLimit $rule): DbStoreOrderGroupRepository
    {
        return new DbStoreOrderGroupRepository(
            $this->connection,
            new DbTtsRenditionRepository($this->connection, new SystemClock()),
            OrderListingMode::CaseOrderPrimary,
            $rule,
        );
    }

    /** @return list<int> the listed order ids, ascending */
    private function listedOrders(DbStoreOrderGroupRepository $listing, ?OrderGroupFilter $filter): array
    {
        $ids = array_map(
            static fn(StoreOrderGroup $group): int => (int) $group->orderId,
            $listing->pageFor(self::A, 50, 0, $filter),
        );
        sort($ids);

        return $ids;
    }

    /** @return list<int> */
    private function dueIds(DbRecordingDurationRepository $repository, DateTimeImmutable $now): array
    {
        return array_map(static fn($r): int => $r->recordingId, $repository->due(1000, 5, $now));
    }

    private function durationOf(int $recordingId): ?string
    {
        $value = (new Query($this->connection))
            ->select('duration_seconds')->from('{{%order58_call_recordings}}')->where(['id' => $recordingId])->scalar();

        return $value === null ? null : (string) $value;
    }

    private function store(int $sourceId): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_stores}}', [
            'source_id' => $sourceId,
            'name' => 'ZZ Duration Rule Store ' . $sourceId,
            'active' => 1,
            'sync_hash' => str_pad('', 64, '0'),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    private function order(int $store, int $order, ?string $total): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_orders}}', [
            'account_id' => $store,
            'source_order_id' => $order,
            'total_amount' => $total,
            'payload_json' => '{}',
            'content_hash' => str_pad((string) $order, 64, '0', STR_PAD_LEFT),
            'source_created_at' => 1_790_000_000 + $order,
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    /** @param list<float|null> $durations */
    private function recordings(int $store, int $order, array $durations): void
    {
        if ($durations === []) {
            return;
        }

        $now = gmdate('Y-m-d H:i:s');
        $session = (string) ($order - 16_000_000);

        $this->connection->createCommand()->insert('{{%order58_call_sessions}}', [
            'store_source_id' => $store,
            'source_order_id' => $order,
            'call_session_id' => $session,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
        $sessionRow = (int) $this->connection->getLastInsertID();

        foreach ($durations as $position => $duration) {
            $url = 'https://order58.xrainbow.com/api/external/recording/fetch/' . $session
                . '?time=2026-10-27&company=SWCC&name=' . $order . '-' . $position;

            $this->connection->createCommand()->insert('{{%order58_call_recordings}}', [
                'session_id' => $sessionRow,
                'position' => $position,
                'url' => $url,
                'url_hash' => hash('sha256', $url),
                'duration_seconds' => $duration,
                'created_at' => $now,
                'updated_at' => $now,
            ])->execute();
            $this->recordingIds[$order][] = (int) $this->connection->getLastInsertID();
        }
    }

    private function assignTopic(int $order): void
    {
        $now = gmdate('Y-m-d H:i:s');

        if ($this->topicId === 0) {
            $this->connection->createCommand()->insert('{{%practice_topics}}', [
                'name' => self::TOPIC,
                'name_key' => 'zz duration rule topic',
                'created_at' => $now,
                'updated_at' => $now,
            ])->execute();
            $this->topicId = (int) $this->connection->getLastInsertID();
        }

        $this->connection->createCommand()->insert('{{%practice_order_topics}}', [
            'store_source_id' => self::A,
            'source_order_id' => $order,
            'topic_id' => $this->topicId,
            'assigned_by_type' => 'ADMIN',
            'assigned_by_id' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    /** Scoped to this test's own stores and topic; never a blanket delete. */
    private function cleanup(): void
    {
        $stores = [self::A, self::B];

        $this->connection->createCommand()->delete('{{%practice_order_topics}}', ['store_source_id' => $stores])->execute();
        $this->connection->createCommand()->delete('{{%practice_topics}}', ['name' => self::TOPIC])->execute();
        // Sessions and recordings cascade from the orders.
        $this->connection->createCommand()->delete('{{%order58_orders}}', ['account_id' => $stores])->execute();
        $this->connection->createCommand()->delete('{{%order58_stores}}', ['source_id' => $stores])->execute();
    }
}
