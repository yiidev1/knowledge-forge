<?php

declare(strict_types=1);

namespace App\Tests\Integration\AudioToText;

use App\AudioToText\Domain\GroupKey;
use App\AudioToText\Domain\RecordingType;
use App\AudioToText\Domain\SourceRole;
use App\AudioToText\Domain\ConversationStatus;
use App\AudioToText\Infrastructure\DbStoreOrderGroupRepository;
use App\AudioToText\Infrastructure\Tts\DbTtsRenditionRepository;
use App\Auth\Infrastructure\DbAdminUserRepository;
use App\Shared\Domain\Clock\SystemClock;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use Yiisoft\Db\Connection\ConnectionInterface;

use function bin2hex;
use function gmdate;
use function sprintf;
use function str_pad;
use function random_bytes;

use const STR_PAD_LEFT;

/**
 * The store page's grouped listing, against real MySQL.
 *
 * ## What this suite exists to pin
 *
 * Three properties that a refactor could break without any page looking obviously wrong:
 *
 * 1. **Grouping is by order, and an upload with no order is its own row.** Fifty of the rows in this
 *    application predate the order field; collapsing them into one "no order" row would merge
 *    unrelated calls and is the single most damaging thing this code could do.
 * 2. **Paging counts groups.** Twenty rows means twenty orders, not twenty uploads — otherwise half an
 *    order lands on page two.
 * 3. **Nothing is discarded.** Two uploads of the same type for one order keep both, newest first.
 *
 * Every row it writes is removed by public id in `_after`; nothing is deleted in bulk, and no existing
 * conversation is read or touched. The store id is far outside the mirrored range so the fixtures
 * cannot collide with live data.
 */
final class StoreOrderGroupRepositoryTest extends Unit
{
    private const STORE = 987654901;
    private const OTHER_STORE = 987654902;

    private ConnectionInterface $connection;
    private DbStoreOrderGroupRepository $repository;
    private int $adminId;

    /** @var list<string> */
    private array $createdConversations = [];

    /** @var list<string> */
    private array $createdJobs = [];

    /** @var list<string> */
    private array $createdUsernames = [];

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->repository = new DbStoreOrderGroupRepository(
            $this->connection,
            new DbTtsRenditionRepository($this->connection, new SystemClock()),
        );

        $username = 'a2t-groups-' . bin2hex(random_bytes(4));
        $this->createdUsernames[] = $username;
        $this->adminId = (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create($username, 'x');
    }

    protected function _after(): void
    {
        foreach ($this->createdJobs as $publicId) {
            IntegrationDb::cleanup($this->connection, '{{%audio_transcription_jobs}}', ['public_id' => $publicId]);
        }

        foreach ($this->createdConversations as $publicId) {
            IntegrationDb::cleanup($this->connection, '{{%audio_conversations}}', ['public_id' => $publicId]);
        }

        foreach ($this->createdUsernames as $username) {
            IntegrationDb::cleanup($this->connection, '{{%admin_users}}', ['username' => $username]);
        }

        $this->createdJobs = [];
        $this->createdConversations = [];
        $this->createdUsernames = [];
    }

    // ------------------------------------------------------------------ grouping

    public function testThreeRecordingsOfOneOrderAreOneRow(): void
    {
        $this->upload(RecordingType::Mixed, '16513791');
        $this->upload(RecordingType::Caller, '16513791');
        $this->upload(RecordingType::Callee, '16513791');

        $groups = $this->repository->pageFor(self::STORE, 20);

        self::assertCount(1, $groups);
        self::assertSame('16513791', $groups[0]->orderId);
        self::assertSame('order:16513791', $groups[0]->key->value);
        self::assertNotNull($groups[0]->mixed);
        self::assertNotNull($groups[0]->caller);
        self::assertNotNull($groups[0]->callee);
        self::assertSame(3, $groups[0]->recordingCount());
    }

    /**
     * **The rule that protects fifty existing rows.**
     *
     * Two uploads that named no order have nothing to do with each other, and must never be presented
     * as one call.
     */
    public function testTwoUploadsWithoutAnOrderStayTwoRows(): void
    {
        $first = $this->upload(RecordingType::Mixed, null);
        $second = $this->upload(RecordingType::Caller, null);

        $groups = $this->repository->pageFor(self::STORE, 20);

        self::assertCount(2, $groups);
        self::assertSame(null, $groups[0]->orderId);
        self::assertSame(null, $groups[1]->orderId);
        self::assertNotSame($groups[0]->key->value, $groups[1]->key->value);
        self::assertSame(
            ['conversation:' . $second, 'conversation:' . $first],
            [$groups[0]->key->value, $groups[1]->key->value],
            'Newest first, and each keyed by its own conversation.',
        );
    }

    public function testAnOrderAndAnOrderlessUploadDoNotMerge(): void
    {
        $this->upload(RecordingType::Mixed, '16513791');
        $this->upload(RecordingType::Mixed, null);

        self::assertCount(2, $this->repository->pageFor(self::STORE, 20));
        self::assertSame(2, $this->repository->countFor(self::STORE));
    }

    // ------------------------------------------------------------------ duplicates

    public function testASecondRecordingOfTheSameTypeIsKeptAsHistory(): void
    {
        $older = $this->upload(RecordingType::Caller, '16513791', '2026-09-01 10:00:00');
        $newer = $this->upload(RecordingType::Caller, '16513791', '2026-09-02 10:00:00');

        $groups = $this->repository->pageFor(self::STORE, 20);
        $caller = $groups[0]->caller;

        self::assertNotNull($caller);
        self::assertSame($newer, $caller->conversationPublicId, 'The newest recording is the primary.');
        self::assertSame(1, $caller->olderCount());
        self::assertSame($older, $caller->older[0]->conversationPublicId, 'The earlier one is kept.');
        self::assertCount(2, $caller->withHistory());
    }

    // ------------------------------------------------------------------ legacy shapes

    /** A pre-recording-type upload is a COMMON conversation and belongs in the mixed column. */
    public function testALegacyUploadWithNoRecordingTypeIsTreatedAsMixed(): void
    {
        $this->upload(null, null);

        $group = $this->repository->pageFor(self::STORE, 20)[0];

        self::assertNotNull($group->mixed);
        self::assertSame(RecordingType::Mixed, $group->mixed->recordingType);
        self::assertFalse($group->isLegacySeparate());
    }

    /**
     * A Customer + Agent pair keeps both halves and is never relabelled.
     *
     * Nothing in this application knows the direction of a call, so calling Customer "Caller" would be
     * inventing a fact to fill a column.
     */
    public function testALegacySeparatePairKeepsBothHalvesAndItsOwnLabels(): void
    {
        $conversation = $this->conversation(null, null, mode: 'SEPARATE');
        $this->job($conversation, SourceRole::Customer);
        $this->job($conversation, SourceRole::Agent);

        $group = $this->repository->pageFor(self::STORE, 20)[0];

        self::assertTrue($group->isLegacySeparate());
        self::assertNull($group->mixed);
        self::assertNull($group->caller);
        self::assertNull($group->callee);
        self::assertCount(2, $group->legacySeparate);
        self::assertSame(
            ['Customer', 'Agent'],
            [$group->legacySeparate[0]->label(), $group->legacySeparate[1]->label()],
        );
        self::assertNull($group->legacySeparate[0]->recordingType);
    }

    // ------------------------------------------------------------------ status

    public function testTheRowStatusIsAggregatedAcrossEveryRecording(): void
    {
        $this->upload(RecordingType::Mixed, '16513791', status: 'COMPLETED');
        $this->upload(RecordingType::Caller, '16513791', status: 'FAILED');

        $group = $this->repository->pageFor(self::STORE, 20)[0];

        self::assertSame(
            ConversationStatus::PARTIALLY_COMPLETED,
            $group->aggregateStatus(),
            'One failed half must not make the working one look lost, nor hide the failure.',
        );
    }

    public function testAllCompletedIsCompleted(): void
    {
        $this->upload(RecordingType::Mixed, '16513791', status: 'COMPLETED');
        $this->upload(RecordingType::Caller, '16513791', status: 'COMPLETED');

        self::assertSame(
            ConversationStatus::COMPLETED,
            $this->repository->pageFor(self::STORE, 20)[0]->aggregateStatus(),
        );
    }

    public function testAnythingOutstandingIsProcessing(): void
    {
        $this->upload(RecordingType::Mixed, '16513791', status: 'COMPLETED');
        $this->upload(RecordingType::Caller, '16513791', status: 'QUEUED');

        self::assertSame(
            ConversationStatus::PROCESSING,
            $this->repository->pageFor(self::STORE, 20)[0]->aggregateStatus(),
        );
    }

    // ------------------------------------------------------------------ paging

    /**
     * **Paging counts orders.**
     *
     * Three uploads of one order plus two lone uploads is three rows, not five — so a page size of two
     * returns two orders and leaves one, rather than slicing an order in half.
     */
    public function testPagingCountsGroupsRatherThanUploads(): void
    {
        $this->upload(RecordingType::Mixed, '16513791');
        $this->upload(RecordingType::Caller, '16513791');
        $this->upload(RecordingType::Callee, '16513791');
        $this->upload(RecordingType::Mixed, null);
        $this->upload(RecordingType::Mixed, null);

        self::assertSame(3, $this->repository->countFor(self::STORE), 'Five uploads, three orders.');

        $first = $this->repository->pageFor(self::STORE, 2, 0);
        $second = $this->repository->pageFor(self::STORE, 2, 2);

        self::assertCount(2, $first);
        self::assertCount(1, $second);

        $keys = [$first[0]->key->value, $first[1]->key->value, $second[0]->key->value];
        self::assertCount(3, array_unique($keys), 'No group may appear on two pages.');
    }

    // ------------------------------------------------------------------ store scoping

    public function testAGroupOfAnotherStoreIsNotVisibleHere(): void
    {
        $mine = $this->upload(RecordingType::Mixed, '16513791');
        $this->upload(RecordingType::Mixed, '99999999', store: self::OTHER_STORE);

        $groups = $this->repository->pageFor(self::STORE, 20);

        self::assertCount(1, $groups);
        self::assertSame($mine, $groups[0]->mixed?->conversationPublicId);

        // And the other store's key cannot be resolved from this store, however it was obtained.
        self::assertNull(
            $this->repository->groupFor(self::STORE, GroupKey::forOrder('99999999')),
            'A key belonging to another store must resolve to nothing.',
        );
    }

    public function testGroupForResolvesOneGroupOfThisStore(): void
    {
        $this->upload(RecordingType::Mixed, '16513791');
        $this->upload(RecordingType::Caller, '16513791');

        $group = $this->repository->groupFor(self::STORE, GroupKey::forOrder('16513791'));

        self::assertNotNull($group);
        self::assertSame('16513791', $group->orderId);
        self::assertSame(2, $group->recordingCount());
    }

    public function testAnUnknownGroupResolvesToNothing(): void
    {
        self::assertNull($this->repository->groupFor(self::STORE, GroupKey::forOrder('404040')));
    }

    // ------------------------------------------------------------------ helpers

    /**
     * A page of twenty orders costs exactly what a page of one costs.
     *
     * This is the property the whole repository is shaped around, and the one a well-meaning edit
     * breaks first: the moment a loop contains a query, a store with real history starts paying for
     * every row it shows. Counted through the server's own statement counter rather than a mock, so
     * it measures what MySQL was actually asked, including anything a lazily-built object triggers.
     *
     * Reading each group afterwards is deliberate. A repository could satisfy a query count by
     * deferring work into the objects it returns, and touching every slot is what stops that passing.
     */
    public function testAPageOfTwentyOrdersCostsWhatAPageOfOneCosts(): void
    {
        $this->upload(RecordingType::Mixed, '900000001');
        $one = $this->countQueries(function (): void {
            $this->readFully($this->repository->pageFor(self::STORE, 20));
        });

        for ($n = 2; $n <= 20; $n++) {
            $this->upload(RecordingType::Mixed, '9000000' . str_pad((string) $n, 2, '0', STR_PAD_LEFT));
        }

        $many = $this->countQueries(function (): void {
            $groups = $this->repository->pageFor(self::STORE, 20);
            self::assertCount(20, $groups);
            $this->readFully($groups);
        });

        self::assertSame(
            $one,
            $many,
            sprintf('One order cost %d queries and twenty cost %d — the listing is paying per row.', $one, $many),
        );
    }

    /**
     * Statements MySQL was asked to run while `$work` ran.
     *
     * `Questions` counts statements sent by this client on this connection. The two readings are taken
     * the same way, so the `SHOW` statements themselves cancel out of the difference.
     */
    private function countQueries(callable $work): int
    {
        $before = $this->questions();
        $work();

        return $this->questions() - $before - 1;
    }

    private function questions(): int
    {
        /** @var array{Value: string}|false $row */
        $row = $this->connection
            ->createCommand("SHOW SESSION STATUS LIKE 'Questions'")
            ->queryOne();

        return $row === false ? 0 : (int) $row['Value'];
    }

    /**
     * Touch everything the page touches, so nothing can be deferred past the count.
     *
     * @param list<\App\AudioToText\Domain\StoreOrderGroup> $groups
     */
    private function readFully(array $groups): void
    {
        foreach ($groups as $group) {
            $group->aggregateStatus();
            $group->hasAnyTranscript();

            foreach ($group->allRecordings() as $slot) {
                $slot->label();
                $slot->isReviewable();
                $slot->aiAudioState();
                $slot->hasGeneratedAudio();
            }
        }
    }

    private function upload(
        ?RecordingType $type,
        ?string $orderId,
        string $createdAt = '2026-09-10 09:00:00',
        string $status = 'COMPLETED',
        int $store = self::STORE,
    ): string {
        $publicId = $this->conversation($type, $orderId, $createdAt, 'COMMON', $store);
        $this->job($publicId, SourceRole::Common, $status);

        return $publicId;
    }

    private function conversation(
        ?RecordingType $type,
        ?string $orderId,
        string $createdAt = '2026-09-10 09:00:00',
        string $mode = 'COMMON',
        int $store = self::STORE,
    ): string {
        $publicId = bin2hex(random_bytes(16));
        $this->createdConversations[] = $publicId;

        $this->connection->createCommand()->insert('{{%audio_conversations}}', [
            'public_id' => $publicId,
            'store_source_id' => $store,
            'mode' => $mode,
            'recording_type' => $type?->value,
            'order_id' => $orderId,
            'generate_ai_audio' => 0,
            'uploaded_by_admin_id' => $this->adminId,
            'created_at' => $createdAt,
        ])->execute();

        return $publicId;
    }

    private function job(string $conversationPublicId, SourceRole $role, string $status = 'COMPLETED'): string
    {
        $conversationId = (int) $this->connection
            ->createCommand(
                'SELECT id FROM {{%audio_conversations}} WHERE public_id = :p',
                [':p' => $conversationPublicId],
            )
            ->queryScalar();

        $publicId = bin2hex(random_bytes(16));
        $this->createdJobs[] = $publicId;

        $this->connection->createCommand()->insert('{{%audio_transcription_jobs}}', [
            'conversation_id' => $conversationId,
            'source_role' => $role->value,
            'transcription_provider' => 'WHISPER',
            'public_id' => $publicId,
            'uploaded_by_admin_id' => $this->adminId,
            'status' => $status,
            'original_filename' => 'call.wav',
            'retained_audio_path' => 'source.wav',
            'duration_seconds' => 12.5,
            'transcript' => $status === 'COMPLETED' ? 'Hello there.' : null,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ])->execute();

        return $publicId;
    }
}
