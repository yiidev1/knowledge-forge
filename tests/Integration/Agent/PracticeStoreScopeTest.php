<?php

declare(strict_types=1);

namespace App\Tests\Integration\Agent;

use App\Agent\Infrastructure\DbPracticeStoreDirectory;
use App\Auth\Infrastructure\DbAdminUserRepository;
use App\Shared\Domain\Clock\SystemClock;
use App\Shared\Web\Actor\PracticeStoreScope;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use Yiisoft\Db\Connection\ConnectionInterface;

use function array_map;
use function bin2hex;
use function gmdate;
use function in_array;
use function random_bytes;
use function str_repeat;
use function implode;
use function substr;

/**
 * Which stores an agent may practise on, against real MySQL.
 *
 * ## Why this suite has to seed everything
 *
 * The development database has exactly one audio-bearing store and every one of its conversations has
 * retained audio, so it cannot tell the candidate predicates apart: all of them return that one store.
 * Eyeballing it would prove nothing. Every case below is therefore built deliberately, one store per
 * case, in a range far outside the mirrored one.
 *
 * ## The three negatives that are the whole point
 *
 * `agent_enabled`, chat eligibility and `active` govern Store Chat and were explicitly excluded from
 * this surface. "We did not use them" is easy to say and easy to break, so each is asserted: a store
 * that fails all three and has order-linked audio **must still be admitted**. If someone later reaches
 * for the chat predicate here, those three cases fail.
 */
final class PracticeStoreScopeTest extends Unit
{
    /** One store per case, so no case can be contaminated by another's rows. */
    private const QUALIFYING = 987655941;
    private const NO_CONVERSATIONS = 987655942;
    private const BLANK_ORDER = 987655943;
    private const NON_NUMERIC_ORDER = 987655944;
    private const ORDER_IN_ANOTHER_STORE = 987655945;
    private const ORDER_NOWHERE = 987655946;
    private const NO_RETAINED_AUDIO = 987655947;
    private const ONE_OF_MANY = 987655948;
    private const UNMIRRORED_STORE = 987655949;
    private const CHAT_DISABLED = 987655950;
    private const NO_KNOWLEDGE_BASE = 987655951;
    private const SOURCE_INACTIVE = 987655952;

    private const ALL_STORES = [
        self::QUALIFYING,
        self::NO_CONVERSATIONS,
        self::BLANK_ORDER,
        self::NON_NUMERIC_ORDER,
        self::ORDER_IN_ANOTHER_STORE,
        self::ORDER_NOWHERE,
        self::NO_RETAINED_AUDIO,
        self::ONE_OF_MANY,
        self::UNMIRRORED_STORE,
        self::CHAT_DISABLED,
        self::NO_KNOWLEDGE_BASE,
        self::SOURCE_INACTIVE,
    ];

    private ConnectionInterface $connection;
    private PracticeStoreScope $scope;
    private DbPracticeStoreDirectory $directory;
    private int $adminId;
    private string $adminUsername;

    /** @var list<string> job public ids, for the per-recording assertions */
    private array $jobs = [];

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->scope = new PracticeStoreScope($this->connection);
        $this->directory = new DbPracticeStoreDirectory($this->connection);

        $this->cleanup();

        $this->adminUsername = 'kf-practice-scope-' . bin2hex(random_bytes(4));
        $this->adminId = (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create($this->adminUsername, 'x');

        // 1. The shape that qualifies: a mirrored store, a mirrored order, a recording with audio.
        $this->store(self::QUALIFYING, 'ZZ Scope Qualifying');
        $this->order(self::QUALIFYING, 16950001);
        $this->jobs['qualifying'] = $this->recording(self::QUALIFYING, '16950001');

        // 2. A store with nothing uploaded at all.
        $this->store(self::NO_CONVERSATIONS, 'ZZ Scope Empty');
        $this->order(self::NO_CONVERSATIONS, 16950002);

        // 3. A recording that named no order. The column is free text and operators leave it blank.
        $this->store(self::BLANK_ORDER, 'ZZ Scope Blank');
        $this->order(self::BLANK_ORDER, 16950003);
        $this->recording(self::BLANK_ORDER, null);

        // 4. A recording whose order id is not a number — '#111', 'unknown', and worse, all real.
        $this->store(self::NON_NUMERIC_ORDER, 'ZZ Scope Words');
        $this->order(self::NON_NUMERIC_ORDER, 16950004);
        $this->recording(self::NON_NUMERIC_ORDER, 'not-a-number');

        // 5. **The collision case.** The order exists — in somebody else's store.
        $this->store(self::ORDER_IN_ANOTHER_STORE, 'ZZ Scope Borrowed');
        $this->jobs['borrowed'] = $this->recording(self::ORDER_IN_ANOTHER_STORE, '16950001');

        // 6. An order id that was never mirrored anywhere. The live `#111` case.
        $this->store(self::ORDER_NOWHERE, 'ZZ Scope Orphan');
        $this->jobs['orphan'] = $this->recording(self::ORDER_NOWHERE, '16950999');

        // 7. A conversation whose job kept no audio. A row is not a recording.
        $this->store(self::NO_RETAINED_AUDIO, 'ZZ Scope Silent');
        $this->order(self::NO_RETAINED_AUDIO, 16950007);
        $this->jobs['silent'] = $this->recording(self::NO_RETAINED_AUDIO, '16950007', retained: false);

        // 8. Three recordings, one of which qualifies. One is enough.
        $this->store(self::ONE_OF_MANY, 'ZZ Scope Mixed');
        $this->order(self::ONE_OF_MANY, 16950008);
        $this->recording(self::ONE_OF_MANY, null);
        $this->recording(self::ONE_OF_MANY, '16950777');
        $this->recording(self::ONE_OF_MANY, '16950008');

        // 9. Everything except a mirrored store row.
        $this->order(self::UNMIRRORED_STORE, 16950009);
        $this->recording(self::UNMIRRORED_STORE, '16950009');

        // 10-12. The three predicates this surface must NOT use.
        $this->store(self::CHAT_DISABLED, 'ZZ Scope Chat Off');
        $this->knowledgeBase(self::CHAT_DISABLED, agentEnabled: false);
        $this->order(self::CHAT_DISABLED, 16950010);
        $this->recording(self::CHAT_DISABLED, '16950010');

        $this->store(self::NO_KNOWLEDGE_BASE, 'ZZ Scope No KB');
        $this->order(self::NO_KNOWLEDGE_BASE, 16950011);
        $this->recording(self::NO_KNOWLEDGE_BASE, '16950011');

        $this->store(self::SOURCE_INACTIVE, 'ZZ Scope Inactive', active: false);
        $this->order(self::SOURCE_INACTIVE, 16950012);
        $this->recording(self::SOURCE_INACTIVE, '16950012');
    }

    protected function _after(): void
    {
        $this->cleanup();
        IntegrationDb::cleanup($this->connection, '{{%admin_users}}', ['username' => $this->adminUsername]);
    }

    // ------------------------------------------------------------------ what qualifies

    public function testAStoreWithOrderLinkedAudioQualifies(): void
    {
        self::assertTrue($this->scope->admitsStore(self::QUALIFYING));
    }

    public function testOneQualifyingRecordingAmongSeveralIsEnough(): void
    {
        self::assertTrue($this->scope->admitsStore(self::ONE_OF_MANY));
    }

    // ------------------------------------------------------------------ what does not

    /** @dataProvider refused */
    public function testStoresWithoutOrderLinkedAudioAreRefused(string $case): void
    {
        $byName = [
            'no conversations' => self::NO_CONVERSATIONS,
            'blank order id' => self::BLANK_ORDER,
            'non-numeric order id' => self::NON_NUMERIC_ORDER,
            'order belongs to another store' => self::ORDER_IN_ANOTHER_STORE,
            'order mirrored nowhere' => self::ORDER_NOWHERE,
            'job kept no audio' => self::NO_RETAINED_AUDIO,
            'store not mirrored' => self::UNMIRRORED_STORE,
        ];

        self::assertFalse($this->scope->admitsStore($byName[$case]), $case);
    }

    /** @return iterable<string, array{string}> */
    public static function refused(): iterable
    {
        foreach ([
            'no conversations',
            'blank order id',
            'non-numeric order id',
            'order belongs to another store',
            'order mirrored nowhere',
            'job kept no audio',
            'store not mirrored',
        ] as $case) {
            yield $case => [$case];
        }
    }

    public function testAnUnknownStoreIsRefused(): void
    {
        self::assertFalse($this->scope->admitsStore(2147480000));
        self::assertFalse($this->scope->admitsStore(0));
        self::assertFalse($this->scope->admitsStore(-1));
    }

    // ------------------------------------------------------------------ the three deliberate negatives

    /**
     * **`agent_enabled` is not this predicate.**
     *
     * A store whose chat an administrator switched off still has its recordings and the orders behind
     * them. If somebody reaches for the chat predicate here, this fails.
     */
    public function testAStoreWithChatDisabledForAgentsStillQualifies(): void
    {
        self::assertTrue($this->scope->admitsStore(self::CHAT_DISABLED));
    }

    /** Chat readiness is not this predicate: a store with no knowledge base at all still qualifies. */
    public function testAStoreWithNoKnowledgeBaseStillQualifies(): void
    {
        self::assertTrue($this->scope->admitsStore(self::NO_KNOWLEDGE_BASE));
    }

    /** Nor is source-active: last month's calls are still worth practising on. */
    public function testASourceInactiveStoreStillQualifies(): void
    {
        self::assertTrue($this->scope->admitsStore(self::SOURCE_INACTIVE));
    }

    // ------------------------------------------------------------------ the listing agrees with the gate

    public function testTheDirectoryListsExactlyTheStoresTheScopeAdmits(): void
    {
        $listed = array_map(
            static fn(object $store): int => $store->sourceId,
            $this->directory->findAvailable(),
        );

        foreach (self::ALL_STORES as $storeSourceId) {
            self::assertSame(
                $this->scope->admitsStore($storeSourceId),
                in_array($storeSourceId, $listed, true),
                'store ' . $storeSourceId . ' must be listed if and only if it is admitted',
            );
        }
    }

    public function testTheCountIsTheNumberOfPractisableRecordings(): void
    {
        foreach ($this->directory->findAvailable() as $store) {
            if ($store->sourceId === self::ONE_OF_MANY) {
                self::assertSame(1, $store->recordingCount, 'only one of the three qualifies');

                return;
            }
        }

        self::fail('the mixed store should have been listed');
    }

    // ------------------------------------------------------------------ one recording at a time

    public function testARecordingInAQualifyingStoreIsAdmitted(): void
    {
        self::assertTrue($this->scope->admitsJob($this->jobs['qualifying']));
    }

    /** Its store qualifies for other reasons; this recording still names nothing real. */
    public function testARecordingNamingAnotherStoresOrderIsRefused(): void
    {
        self::assertFalse($this->scope->admitsJob($this->jobs['borrowed']));
    }

    public function testARecordingNamingNoMirroredOrderIsRefused(): void
    {
        self::assertFalse($this->scope->admitsJob($this->jobs['orphan']));
    }

    /**
     * A transcript stays readable when the audio has aged out.
     *
     * The job-level check deliberately does not require retained audio — the file endpoints refuse on
     * their own when there are no bytes, and hiding a readable transcript to prevent a download that
     * cannot happen would be the wrong trade. The *store* still has to qualify, which this one does
     * not, so the refusal here is about the store.
     */
    public function testARecordingInANonQualifyingStoreIsRefused(): void
    {
        self::assertFalse($this->scope->admitsJob($this->jobs['silent']));
    }

    public function testAMalformedJobIdIsRefusedWithoutAQuery(): void
    {
        self::assertFalse($this->scope->admitsJob(''));
        self::assertFalse($this->scope->admitsJob('not-hex'));
        self::assertFalse($this->scope->admitsJob(str_repeat('f', 31)));
        self::assertFalse($this->scope->admitsJob(str_repeat('f', 32)));
    }

    // ------------------------------------------------------------------ fixtures

    private function store(int $sourceId, string $name, bool $active = true): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_stores}}', [
            'source_id' => $sourceId,
            'name' => $name,
            'active' => $active ? 1 : 0,
            'sync_hash' => str_repeat('0', 64),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    private function knowledgeBase(int $sourceId, bool $agentEnabled): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%knowledge_bases}}', [
            'name' => 'ZZ Scope KB ' . $sourceId,
            'slug' => 'zz-scope-kb-' . $sourceId,
            'source_system' => 'order58',
            'source_store_id' => $sourceId,
            'source_name' => 'ZZ Scope KB ' . $sourceId,
            'source_active' => 1,
            'agent_enabled' => $agentEnabled ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    private function order(int $accountId, int $sourceOrderId): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_orders}}', [
            'account_id' => $accountId,
            'source_order_id' => $sourceOrderId,
            'payload_json' => '{}',
            'content_hash' => str_repeat('0', 64),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    /** @return string the job's public id */
    private function recording(int $storeSourceId, ?string $orderId, bool $retained = true): string
    {
        $now = gmdate('Y-m-d H:i:s');
        $conversationId = bin2hex(random_bytes(16));
        $jobId = bin2hex(random_bytes(16));

        // One transaction for the pair. The job's foreign key points at the conversation, so between
        // the insert and the id lookup there is a window in which the row can be gone — and in a full
        // suite run, sharing a development database with suites that sweep this table, it has been.
        // Inside a transaction the id this reads is one only this connection can see.
        $this->connection->transaction(function () use (
            $conversationId,
            $jobId,
            $storeSourceId,
            $orderId,
            $retained,
            $now,
        ): void {
            $this->connection->createCommand()->insert('{{%audio_conversations}}', [
                'public_id' => $conversationId,
                'store_source_id' => $storeSourceId,
                'mode' => 'COMMON',
                'recording_type' => 'MIXED',
                'order_id' => $orderId === null ? null : substr($orderId, 0, 32),
                'generate_ai_audio' => 0,
                'uploaded_by_admin_id' => $this->adminId,
                'created_at' => $now,
            ])->execute();

            $rowId = (int) $this->connection->getLastInsertID();

            self::assertGreaterThan(0, $rowId, 'the conversation should have been written');

            $this->connection->createCommand()->insert('{{%audio_transcription_jobs}}', [
                'public_id' => $jobId,
                'conversation_id' => $rowId,
                'uploaded_by_admin_id' => $this->adminId,
                'original_filename' => 'scope.wav',
                'retained_audio_path' => $retained ? 'source.wav' : null,
                'source_role' => 'COMMON',
                'status' => 'COMPLETED',
                'processing_stage' => 'COMPLETED',
                'transcription_provider' => 'WHISPER',
                'duration_seconds' => 1.0,
                'review_count' => 0,
                'created_at' => $now,
            ])->execute();
        });

        return $jobId;
    }

    /** Scoped to this suite's own store ids. Never a blanket delete. */
    private function cleanup(): void
    {
        $ids = $this->connection
            ->createCommand(
                'SELECT id FROM {{%audio_conversations}} WHERE store_source_id IN ('
                . implode(',', self::ALL_STORES) . ')',
            )
            ->queryColumn();

        foreach ($ids as $id) {
            $this->connection->createCommand()
                ->delete('{{%audio_transcription_jobs}}', ['conversation_id' => (int) $id])->execute();
        }

        foreach (self::ALL_STORES as $storeSourceId) {
            $this->connection->createCommand()
                ->delete('{{%audio_conversations}}', ['store_source_id' => $storeSourceId])->execute();
            $this->connection->createCommand()
                ->delete('{{%order58_orders}}', ['account_id' => $storeSourceId])->execute();
            $this->connection->createCommand()
                ->delete('{{%knowledge_bases}}', ['source_store_id' => $storeSourceId])->execute();
            $this->connection->createCommand()
                ->delete('{{%order58_stores}}', ['source_id' => $storeSourceId])->execute();
        }
    }
}
