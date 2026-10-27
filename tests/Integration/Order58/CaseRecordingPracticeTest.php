<?php

declare(strict_types=1);

namespace App\Tests\Integration\Order58;

use App\Agent\Infrastructure\DbPracticeStoreDirectory;
use App\AudioToText\Domain\OrderGroupFilter;
use App\AudioToText\Domain\OrderListingMode;
use App\AudioToText\Infrastructure\DbCaseRecordingReader;
use App\AudioToText\Infrastructure\DbStoreOrderGroupRepository;
use App\AudioToText\Infrastructure\Tts\DbTtsRenditionRepository;
use App\Auth\Infrastructure\DbAdminUserRepository;
use App\Order58\Infrastructure\DbStoreAudioCounts;
use App\Shared\Domain\Clock\SystemClock;
use App\Shared\Infrastructure\Db\DatabaseTarget;
use App\Shared\Web\Actor\PracticeStoreScope;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function array_map;
use function bin2hex;
use function gmdate;
use function hash;
use function random_bytes;
use function str_pad;

use const STR_PAD_LEFT;

/**
 * Case Order recordings wherever a store's practice audio is counted, listed or admitted.
 *
 * One store, ten orders, and a carefully uneven spread of recordings — so each rule can be told apart:
 *
 * | orders        | recordings                         |
 * |---------------|------------------------------------|
 * | 901..904      | one link each                      |
 * | 905           | three links in one call            |
 * | 906           | two calls, one link each           |
 * | 907           | one link, later replaced (delisted) and one current |
 * | 908           | an uploaded recording only         |
 * | 909, 910      | nothing at all                     |
 *
 * Fixture stores are `987655998` and `987655999`; nothing outside them is read or written.
 */
final class CaseRecordingPracticeTest extends Unit
{
    private const STORE = 987655998;
    private const OTHER = 987655999;

    private ConnectionInterface $connection;
    private int $adminId = 0;
    private bool $ready = false;

    /** @var array<int, list<int>> recording ids by order */
    private array $recordingIds = [];

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        DatabaseTarget::resolve($this->connection)->assertLocalForSchemaChange();
        $this->ready = true;
        $this->cleanup();

        $this->adminId = (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create('case-practice-' . bin2hex(random_bytes(4)), 'x');

        $this->store(self::STORE, 'Case Practice Fixture');
        $this->store(self::OTHER, 'Case Practice Other');

        foreach ([901, 902, 903, 904, 905, 906, 907, 908, 909, 910] as $order) {
            $this->order(self::STORE, $order);
        }

        $this->order(self::OTHER, 901);

        foreach ([901, 902, 903, 904] as $order) {
            $this->recordings(self::STORE, $order, (string) (800000 + $order), 1);
        }

        $this->recordings(self::STORE, 905, '800905', 3);
        $this->recordings(self::STORE, 906, '800906', 1);
        $this->recordings(self::STORE, 906, '800916', 1);
        $this->recordings(self::STORE, 907, '800907', 2, delistFirst: true);
        $this->uploaded(self::STORE, 908);
    }

    protected function _after(): void
    {
        if ($this->ready) {
            $this->cleanup();
        }
    }

    // --------------------------------------------------------------------- store card counts

    /**
     * **Orders with a recording, not links and not every order.** 901–907 have a current link: 7.
     * 905's three links count once, 906's two calls count once, 907's replaced link not at all, and
     * the uploaded-only and empty orders not at all.
     */
    public function testTheCardCountsDistinctOrdersWithACurrentLink(): void
    {
        $counts = (new DbStoreAudioCounts($this->connection))->caseRecordingCountsFor([self::STORE, self::OTHER]);

        self::assertSame(7, $counts[self::STORE]->orders);
        self::assertSame(8, $counts[self::STORE]->calls, '901-904, 905, two for 906, 907');
        self::assertSame(10, $counts[self::STORE]->files, '4 + 3 + 2 + 1 current');
        self::assertArrayNotHasKey(self::OTHER, $counts, 'a store with no links is absent, which reads as zero');
    }

    /** The filter and the card use one rule: listed exactly when the card is non-zero. */
    public function testTheFilterListsExactlyTheStoresWithACurrentLink(): void
    {
        $stores = (new DbStoreAudioCounts($this->connection))->storesWithCaseRecordings();

        self::assertContains(self::STORE, $stores);
        self::assertNotContains(self::OTHER, $stores);
    }

    /** Uploaded audio keeps its own count, untouched by recording links. */
    public function testTheUploadedAudioCountIsUnchanged(): void
    {
        $counts = (new DbStoreAudioCounts($this->connection))->countsFor([self::STORE], false);

        self::assertSame(1, $counts[self::STORE]->orders, 'only 908 was uploaded');
    }

    // --------------------------------------------------------------------- the store page

    public function testThePageReaderReturnsCurrentLinksInOrderAndNumbersThem(): void
    {
        $reader = new DbCaseRecordingReader($this->connection);
        $page = $reader->forOrders(self::STORE, ['905', '906', '907', '909', 'not-an-order']);

        self::assertSame([1, 2, 3], array_map(static fn($r): int => $r->number, $page['905']));
        self::assertSame($this->recordingIds[905], array_map(static fn($r): int => $r->recordingId, $page['905']));
        self::assertSame(['800906', '800916'], array_map(static fn($r): string => $r->callSessionId, $page['906']));
        self::assertCount(1, $page['907'], 'the replaced link is not offered');
        self::assertArrayNotHasKey('909', $page);
    }

    public function testThePageReaderNeverReachesAnotherStore(): void
    {
        $this->recordings(self::OTHER, 901, '899901', 1);

        $page = (new DbCaseRecordingReader($this->connection))->forOrders(self::STORE, ['901']);

        self::assertSame(['800901'], array_map(static fn($r): string => $r->callSessionId, $page['901']));
    }

    // --------------------------------------------------------------------- the practice listing

    /**
     * **Practice lists every order with something to listen to** — uploaded or a recording link — and
     * nothing else, and the pager agrees with the page.
     */
    public function testThePracticeListingAcceptsEitherSource(): void
    {
        $repository = $this->listing();
        $filter = OrderGroupFilter::linkedOrdersOnly();

        $keys = array_map(
            static fn($group): ?string => $group->orderId,
            $repository->pageFor(self::STORE, 50, 0, $filter),
        );

        self::assertEqualsCanonicalizing(['901', '902', '903', '904', '905', '906', '907', '908'], $keys);
        self::assertSame(8, $repository->countFor(self::STORE, $filter));
        self::assertCount(3, $repository->pageFor(self::STORE, 5, 5, $filter), 'paged over the narrowed set');
    }

    /** A replaced link does not keep an order practisable on its own. */
    public function testAnOrderWhoseOnlyLinkWasReplacedIsNotPractisable(): void
    {
        $this->connection->createCommand()->update(
            '{{%order58_call_recordings}}',
            ['delisted_at' => gmdate('Y-m-d H:i:s')],
            ['id' => $this->recordingIds[901]],
        )->execute();

        $keys = array_map(
            static fn($group): ?string => $group->orderId,
            $this->listing()->pageFor(self::STORE, 50, 0, OrderGroupFilter::linkedOrdersOnly()),
        );

        self::assertNotContains('901', $keys);
    }

    // --------------------------------------------------------------------- agent scope

    public function testAStoreWithOnlyRecordingLinksIsOpenToAgents(): void
    {
        $this->removeUploads();

        $scope = new PracticeStoreScope($this->connection);

        self::assertTrue($scope->admitsStore(self::STORE));
        self::assertFalse($scope->admitsStore(self::OTHER), 'orders but no recording of any kind');
    }

    /** All three ids must belong together, and the link must be current. */
    public function testARecordingIsAdmittedOnlyThroughItsOwnOrderAndStore(): void
    {
        $scope = new PracticeStoreScope($this->connection);
        $id = $this->recordingIds[901][0];

        self::assertTrue($scope->admitsCaseRecording(self::STORE, 901, $id));
        self::assertFalse($scope->admitsCaseRecording(self::STORE, 902, $id), 'another order');
        self::assertFalse($scope->admitsCaseRecording(self::OTHER, 901, $id), 'another store');
        self::assertFalse($scope->admitsCaseRecording(self::STORE, 907, $this->recordingIds[907][0]), 'replaced');
        self::assertTrue($scope->admitsCaseRecording(self::STORE, 907, $this->recordingIds[907][1]));
    }

    /**
     * The agent's store list counts what there is to listen to, once per order source: the uploaded
     * recording for 908, and every current link of the orders that have no upload — never both.
     */
    public function testTheAgentDirectoryCountsEachRecordingOnce(): void
    {
        // 901 also gets an upload: its link must then stop counting, the upload counts instead.
        $this->uploaded(self::STORE, 901);

        $stores = (new DbPracticeStoreDirectory($this->connection))->findAvailable();
        $mine = null;

        foreach ($stores as $store) {
            if ($store->sourceId === self::STORE) {
                $mine = $store;
            }
        }

        self::assertNotNull($mine);
        // Uploads: 908, 901 = 2. Links on orders without an upload: 902-904 (3) + 905 (3) + 906 (2) + 907 (1).
        self::assertSame(2 + 9, $mine->recordingCount);
    }

    // --------------------------------------------------------------------- fixtures

    private function listing(): DbStoreOrderGroupRepository
    {
        return new DbStoreOrderGroupRepository(
            $this->connection,
            new DbTtsRenditionRepository($this->connection, new SystemClock()),
            OrderListingMode::RecordingPrimary,
        );
    }

    private function store(int $sourceId, string $name): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_stores}}', [
            'source_id' => $sourceId,
            'name' => $name,
            'active' => 1,
            'sync_hash' => str_pad('', 64, '0'),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    private function order(int $store, int $order): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_orders}}', [
            'account_id' => $store,
            'source_order_id' => $order,
            'payload_json' => '{}',
            'content_hash' => str_pad((string) $order, 64, '0', STR_PAD_LEFT),
            'source_created_at' => 1_790_000_000 + $order,
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    private function recordings(int $store, int $order, string $session, int $count, bool $delistFirst = false): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_call_sessions}}', [
            'store_source_id' => $store,
            'source_order_id' => $order,
            'call_session_id' => $session,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        $sessionRow = (int) $this->connection->getLastInsertID();

        for ($position = 0; $position < $count; ++$position) {
            $url = 'https://order58.xrainbow.com/api/external/recording/fetch/' . $session
                . '?time=2026-10-27&company=SWCC&name=' . $order . '-' . $position;

            $this->connection->createCommand()->insert('{{%order58_call_recordings}}', [
                'session_id' => $sessionRow,
                'position' => $position,
                'url' => $url,
                'url_hash' => hash('sha256', $url),
                'delisted_at' => $delistFirst && $position === 0 ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ])->execute();

            if ($store === self::STORE) {
                $this->recordingIds[$order][] = (int) $this->connection->getLastInsertID();
            }
        }
    }

    /** An uploaded Mix recording with its audio retained, the way the store page's upload stores one. */
    private function uploaded(int $store, int $order): void
    {
        $conversation = bin2hex(random_bytes(16));

        $this->connection->createCommand()->insert('{{%audio_conversations}}', [
            'public_id' => $conversation,
            'store_source_id' => $store,
            'mode' => 'COMMON',
            'recording_type' => 'MIXED',
            'order_id' => (string) $order,
            'generate_ai_audio' => 0,
            'uploaded_by_admin_id' => $this->adminId,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ])->execute();

        $this->connection->createCommand()->insert('{{%audio_transcription_jobs}}', [
            'conversation_id' => (int) $this->connection->getLastInsertID(),
            'public_id' => bin2hex(random_bytes(16)),
            'uploaded_by_admin_id' => $this->adminId,
            'status' => 'NOT_REQUESTED',
            'original_filename' => 'fixture.wav',
            'retained_audio_path' => 'source.wav',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ])->execute();
    }

    /** Jobs before conversations: the foreign key between them is RESTRICT. */
    private function removeUploads(): void
    {
        $conversations = (new Query($this->connection))
            ->select('id')
            ->from('{{%audio_conversations}}')
            ->where(['store_source_id' => [self::STORE, self::OTHER]])
            ->column();

        if ($conversations !== []) {
            $this->connection->createCommand()
                ->delete('{{%audio_transcription_jobs}}', ['conversation_id' => $conversations])->execute();
            $this->connection->createCommand()
                ->delete('{{%audio_conversations}}', ['id' => $conversations])->execute();
        }
    }

    private function cleanup(): void
    {
        $stores = [self::STORE, self::OTHER];

        $this->removeUploads();

        $this->connection->createCommand()
            ->delete('{{%order58_orders}}', ['account_id' => $stores])->execute();
        $this->connection->createCommand()
            ->delete('{{%order58_stores}}', ['source_id' => $stores])->execute();

        if ($this->adminId > 0) {
            $this->connection->createCommand()->delete('{{%admin_users}}', ['id' => $this->adminId])->execute();
        }
    }
}
