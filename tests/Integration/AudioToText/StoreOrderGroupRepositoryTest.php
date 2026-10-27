<?php

declare(strict_types=1);

namespace App\Tests\Integration\AudioToText;

use App\AudioToText\Domain\GroupKey;
use App\AudioToText\Domain\OrderGroupFilter;
use App\AudioToText\Domain\OrderListingMode;
use App\AudioToText\Domain\StoreOrderGroup;
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

use function array_map;
use function array_unique;
use function array_values;
use function bin2hex;
use function gmdate;
use function sprintf;
use function sort;
use function str_pad;
use function strtolower;
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

    /** The listing as recordings produce it: `ORDER58_CASE_ORDER_PRIMARY=false`. */
    private DbStoreOrderGroupRepository $repository;

    /** The listing as imported orders produce it: `ORDER58_CASE_ORDER_PRIMARY=true`, the default. */
    private DbStoreOrderGroupRepository $caseOrderFirst;
    private int $adminId;

    /** @var list<string> */
    private array $createdConversations = [];

    /** @var list<string> */
    private array $createdJobs = [];

    /** @var list<string> */
    private array $createdUsernames = [];

    /** @var list<string> topic names written by {@see topic()} */
    private array $createdTopics = [];

    /** @var list<array{int, string}> store id and order id of every assignment written */
    private array $assignedOrders = [];

    /** @var list<array{int, string}> store id and order id of every mirrored order written */
    private array $mirroredOrders = [];

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();

        $renditions = new DbTtsRenditionRepository($this->connection, new SystemClock());

        /*
         * `$this->repository` is the RECORDING-primary listing, and deliberately so.
         *
         * Everything above the mode section — grouping, replacement history, legacy pairs, aggregate
         * status, the agent predicate — describes how recordings become rows, which is what that mode
         * is. Running those cases under the other mode would be asking a question about recordings
         * through a query whose primary table is orders.
         *
         * The case-order-primary listing gets its own instance, and the mode cases below use both.
         */
        $this->repository = new DbStoreOrderGroupRepository(
            $this->connection,
            $renditions,
            OrderListingMode::RecordingPrimary,
        );

        $this->caseOrderFirst = new DbStoreOrderGroupRepository(
            $this->connection,
            $renditions,
            OrderListingMode::CaseOrderPrimary,
        );

        $username = 'a2t-groups-' . bin2hex(random_bytes(4));
        $this->createdUsernames[] = $username;
        $this->adminId = (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create($username, 'x');
    }

    protected function _after(): void
    {
        // Assignments before topics: the foreign key is RESTRICT, so a topic cannot go while a row
        // still points at it — which is the behaviour the Practice suite pins, and this relies on.
        foreach ($this->assignedOrders as [$store, $orderId]) {
            IntegrationDb::cleanup($this->connection, '{{%practice_order_topics}}', [
                'store_source_id' => $store,
                'source_order_id' => $orderId,
            ]);
        }

        foreach ($this->createdTopics as $name) {
            IntegrationDb::cleanup($this->connection, '{{%practice_topics}}', ['name' => $name]);
        }

        foreach ($this->mirroredOrders as [$store, $orderId]) {
            IntegrationDb::cleanup($this->connection, '{{%order58_orders}}', [
                'account_id' => $store,
                'source_order_id' => $orderId,
            ]);
        }

        $this->assignedOrders = [];
        $this->createdTopics = [];
        $this->mirroredOrders = [];

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

    // ------------------------------------------------------------------ replacement

    /**
     * A replacement that has not finished yet does not take over.
     *
     * This is the property the whole Manage Audio feature rests on. An administrator who replaces the
     * caller side has a working recording throughout — were "current" simply the newest row, the order
     * would show a recording with no transcript and nothing to correct from the moment the replacement
     * was queued until whenever the worker got to it.
     */
    public function testAReplacementStillProcessingDoesNotBecomeCurrent(): void
    {
        $good = $this->upload(RecordingType::Caller, '16513791', '2026-09-01 10:00:00');
        $pending = $this->upload(RecordingType::Caller, '16513791', '2026-09-02 10:00:00', 'QUEUED');

        $caller = $this->repository->pageFor(self::STORE, 20)[0]->caller;

        self::assertNotNull($caller);
        self::assertSame($good, $caller->conversationPublicId, 'The finished recording is still current.');
        self::assertSame(1, $caller->olderCount());
        self::assertSame($pending, $caller->older[0]->conversationPublicId, 'The replacement is visible.');
    }

    /** And a replacement that failed never does, however new it is. */
    public function testAFailedReplacementLeavesThePreviousRecordingCurrent(): void
    {
        $good = $this->upload(RecordingType::Callee, '16513791', '2026-09-01 10:00:00');
        $this->upload(RecordingType::Callee, '16513791', '2026-09-03 10:00:00', 'FAILED');

        $callee = $this->repository->pageFor(self::STORE, 20)[0]->callee;

        self::assertNotNull($callee);
        self::assertSame($good, $callee->conversationPublicId);
    }

    /** Once it finishes, it takes over — and the recording it replaced is kept, not removed. */
    public function testACompletedReplacementBecomesCurrentAndKeepsTheOneItReplaced(): void
    {
        $first = $this->upload(RecordingType::Caller, '16513791', '2026-09-01 10:00:00');
        $second = $this->upload(RecordingType::Caller, '16513791', '2026-09-02 10:00:00');

        $caller = $this->repository->pageFor(self::STORE, 20)[0]->caller;

        self::assertNotNull($caller);
        self::assertSame($second, $caller->conversationPublicId);
        self::assertSame($first, $caller->older[0]->conversationPublicId);
    }

    /**
     * A worker finishing late for an older replacement cannot overtake a newer one.
     *
     * Two replacements are uploaded, the newer finishes first, and then the older one finishes too.
     * Ordering is by the id the upload was given, never by when a transcription happened to end, so
     * the newer upload stays current — which is what stops a slow queue quietly reinstating a
     * recording the administrator had already replaced again.
     */
    public function testALateWorkerCannotMakeAnOlderReplacementCurrent(): void
    {
        $this->upload(RecordingType::Caller, '16513791', '2026-09-01 10:00:00');
        $middle = $this->conversation(RecordingType::Caller, '16513791', '2026-09-02 10:00:00');
        $this->job($middle, SourceRole::Common, 'PROCESSING');
        $newest = $this->upload(RecordingType::Caller, '16513791', '2026-09-03 10:00:00');

        // The newest one won while the middle upload was still running.
        self::assertSame(
            $newest,
            $this->repository->pageFor(self::STORE, 20)[0]->caller?->conversationPublicId,
        );

        // The middle one now finishes, later in wall-clock time than the winner did.
        $this->connection->createCommand()->update(
            '{{%audio_transcription_jobs}}',
            ['status' => 'COMPLETED'],
            ['conversation_id' => $this->conversationId($middle)],
        )->execute();

        self::assertSame(
            $newest,
            $this->repository->pageFor(self::STORE, 20)[0]->caller?->conversationPublicId,
            'A late finish does not reinstate an upload that had already been superseded.',
        );
    }

    /** Replacing one side leaves the other two exactly as they were. */
    public function testReplacingOneRecordingLeavesTheOtherTwoAlone(): void
    {
        $mixed = $this->upload(RecordingType::Mixed, '16513791', '2026-09-01 10:00:00');
        $caller = $this->upload(RecordingType::Caller, '16513791', '2026-09-01 10:01:00');
        $callee = $this->upload(RecordingType::Callee, '16513791', '2026-09-01 10:02:00');

        $replacement = $this->upload(RecordingType::Caller, '16513791', '2026-09-04 10:00:00');

        $group = $this->repository->pageFor(self::STORE, 20)[0];

        self::assertSame($mixed, $group->mixed?->conversationPublicId, 'Mixed is untouched.');
        self::assertSame($callee, $group->callee?->conversationPublicId, 'Callee is untouched.');
        self::assertSame($replacement, $group->caller?->conversationPublicId);
        self::assertSame(0, $group->mixed?->olderCount());
        self::assertSame(0, $group->callee?->olderCount());
        self::assertSame($caller, $group->caller?->older[0]->conversationPublicId);
    }

    /**
     * A row's status describes the recordings it is using, not the ones it stopped using.
     *
     * Without this an order whose caller replacement is still queued would report itself as partially
     * complete, and one whose replacement failed would report a failure — while all three recordings
     * it is actually using are finished and correct.
     */
    public function testAnInFlightOrFailedReplacementDoesNotChangeTheRowsStatus(): void
    {
        $this->upload(RecordingType::Mixed, '16513791', '2026-09-01 10:00:00');
        $this->upload(RecordingType::Caller, '16513791', '2026-09-01 10:01:00');
        $this->upload(RecordingType::Caller, '16513791', '2026-09-05 10:00:00', 'QUEUED');
        $this->upload(RecordingType::Callee, '16513791', '2026-09-01 10:02:00');
        $this->upload(RecordingType::Callee, '16513791', '2026-09-06 10:00:00', 'FAILED');

        self::assertSame(
            ConversationStatus::COMPLETED,
            $this->repository->pageFor(self::STORE, 20)[0]->aggregateStatus(),
        );
    }

    /** Nothing is hidden, though: the count still speaks for every recording ever uploaded. */
    public function testEveryVersionIsStillCounted(): void
    {
        $this->upload(RecordingType::Caller, '16513791', '2026-09-01 10:00:00');
        $this->upload(RecordingType::Caller, '16513791', '2026-09-02 10:00:00');

        self::assertSame(2, $this->repository->pageFor(self::STORE, 20)[0]->recordingCount());
    }

    private function conversationId(string $publicId): int
    {
        return (int) $this->connection
            ->createCommand(
                'SELECT id FROM {{%audio_conversations}} WHERE public_id = :p',
                [':p' => $publicId],
            )
            ->queryScalar();
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

    // ------------------------------------------------------------------ the topic filter

    /**
     * Null changes nothing — the property every other surface depends on.
     *
     * Order Testing and the audio store page pass no filter, so a page fetched with null must be the
     * same page it was before filtering existed, topics in the database or not.
     */
    public function testNoFilterReturnsEveryOrderEvenWhenTopicsExist(): void
    {
        $this->upload(RecordingType::Mixed, '16513791');
        $this->upload(RecordingType::Mixed, '16513792');
        $this->upload(RecordingType::Mixed, null);
        $this->assignTopic('16513791', $this->topic('ZZ Groups Filter A'));

        self::assertCount(3, $this->repository->pageFor(self::STORE, 20));
        self::assertCount(3, $this->repository->pageFor(self::STORE, 20, 0, null));
        self::assertSame(3, $this->repository->countFor(self::STORE));
        self::assertSame(3, $this->repository->countFor(self::STORE, null));
    }

    public function testFilteringByTopicReturnsOnlyThatTopicsOrders(): void
    {
        $wanted = $this->topic('ZZ Groups Filter A');
        $other = $this->topic('ZZ Groups Filter B');

        $this->upload(RecordingType::Mixed, '16513791');
        $this->upload(RecordingType::Mixed, '16513792');
        $this->upload(RecordingType::Mixed, '16513793');

        $this->assignTopic('16513791', $wanted);
        $this->assignTopic('16513792', $other);

        $groups = $this->repository->pageFor(self::STORE, 20, 0, self::topicFilter([$wanted]));

        self::assertCount(1, $groups);
        self::assertSame('16513791', $groups[0]->orderId);
    }

    /**
     * The count and the page answer the same question.
     *
     * A pager built from an unfiltered count offers pages the filtered list cannot fill, which is the
     * other half of the paging bug this repository exists to prevent.
     */
    public function testTheCountAgreesWithThePageUnderTheSameFilter(): void
    {
        $topic = $this->topic('ZZ Groups Filter A');

        $this->upload(RecordingType::Mixed, '16513791');
        $this->upload(RecordingType::Mixed, '16513792');
        $this->upload(RecordingType::Mixed, '16513793');
        $this->assignTopic('16513791', $topic);
        $this->assignTopic('16513792', $topic);

        $filter = self::topicFilter([$topic]);

        self::assertSame(2, $this->repository->countFor(self::STORE, $filter));
        self::assertCount(2, $this->repository->pageFor(self::STORE, 20, 0, $filter));
    }

    /** Several recordings of one order are still one row when the filter is on. */
    public function testFilteringStillGroupsByOrder(): void
    {
        $topic = $this->topic('ZZ Groups Filter A');

        $this->upload(RecordingType::Mixed, '16513791');
        $this->upload(RecordingType::Caller, '16513791');
        $this->upload(RecordingType::Callee, '16513791');
        $this->assignTopic('16513791', $topic);

        $groups = $this->repository->pageFor(self::STORE, 20, 0, self::topicFilter([$topic]));

        self::assertCount(1, $groups);
        self::assertSame(3, $groups[0]->recordingCount());
        self::assertSame(1, $this->repository->countFor(self::STORE, self::topicFilter([$topic])));
    }

    public function testUnassignedReturnsOrdersThatCarryNoTopic(): void
    {
        $topic = $this->topic('ZZ Groups Filter A');

        $this->upload(RecordingType::Mixed, '16513791');
        $this->upload(RecordingType::Mixed, '16513792');
        $this->assignTopic('16513791', $topic);

        $groups = $this->repository->pageFor(self::STORE, 20, 0, self::topicFilter([], true));

        self::assertCount(1, $groups);
        self::assertSame('16513792', $groups[0]->orderId);
        self::assertSame(1, $this->repository->countFor(self::STORE, self::topicFilter([], true)));
    }

    /**
     * An upload that named no order is not "unassigned" — it is unassignable.
     *
     * It groups under its own conversation key and no topic row can ever reference it, so a filter whose
     * purpose is "what still needs a topic" must not list it. It stays visible unfiltered, which the
     * no-filter test above pins.
     */
    public function testUnassignedExcludesUploadsWithNoOrderAtAll(): void
    {
        $this->upload(RecordingType::Mixed, null);
        $this->upload(RecordingType::Mixed, '16513792');

        $groups = $this->repository->pageFor(self::STORE, 20, 0, self::topicFilter([], true));

        self::assertCount(1, $groups);
        self::assertSame('16513792', $groups[0]->orderId);
    }

    /**
     * **The collision case the correlation exists for.**
     *
     * `source_order_id` is unique only within a store. Another store's topic row for the same order
     * number must not make this store's identically numbered order match.
     */
    public function testAnotherStoresTopicRowDoesNotMatchThisStoresOrder(): void
    {
        $topic = $this->topic('ZZ Groups Filter A');

        $this->upload(RecordingType::Mixed, '16513791');
        $this->assignTopic('16513791', $topic, self::OTHER_STORE);

        self::assertCount(
            0,
            $this->repository->pageFor(self::STORE, 20, 0, self::topicFilter([$topic])),
            'The topic row belongs to another store and must not match here.',
        );
        self::assertCount(
            1,
            $this->repository->pageFor(self::STORE, 20, 0, self::topicFilter([], true)),
            'And this store\'s order is still untopicked.',
        );
    }

    public function testFilteringPagesByGroupLikeTheUnfilteredQuery(): void
    {
        $topic = $this->topic('ZZ Groups Filter A');

        foreach (['16513791', '16513792', '16513793'] as $orderId) {
            $this->upload(RecordingType::Mixed, $orderId);
            $this->upload(RecordingType::Caller, $orderId);
            $this->assignTopic($orderId, $topic);
        }

        $filter = self::topicFilter([$topic]);

        self::assertCount(2, $this->repository->pageFor(self::STORE, 2, 0, $filter));
        self::assertCount(1, $this->repository->pageFor(self::STORE, 2, 2, $filter));
        self::assertSame(3, $this->repository->countFor(self::STORE, $filter));
    }

    // ------------------------------------------------------------------ several topics at once

    /**
     * **OR, not AND.** An order matches if it carries *any* of the chosen topics.
     *
     * An order carries at most one topic, so AND across two topics would match nothing at all — which
     * is exactly the shape of bug that looks like "the filter is broken" rather than "the filter means
     * something else".
     */
    public function testSeveralTopicsMatchOrdersCarryingAnyOfThem(): void
    {
        $first = $this->topic('ZZ Groups Filter A');
        $second = $this->topic('ZZ Groups Filter B');

        $this->upload(RecordingType::Mixed, '16513791');
        $this->upload(RecordingType::Mixed, '16513792');
        $this->upload(RecordingType::Mixed, '16513793');
        $this->assignTopic('16513791', $first);
        $this->assignTopic('16513792', $second);

        $filter = self::topicFilter([$first, $second]);
        $orders = array_map(
            static fn(StoreOrderGroup $group): ?string => $group->orderId,
            $this->repository->pageFor(self::STORE, 20, 0, $filter),
        );
        sort($orders);

        self::assertSame(['16513791', '16513792'], $orders);
        self::assertSame(2, $this->repository->countFor(self::STORE, $filter));
    }

    /** One order appears once however many of the chosen topics it could have matched. */
    public function testAnOrderIsListedOnceNotOncePerChosenTopic(): void
    {
        $first = $this->topic('ZZ Groups Filter A');
        $second = $this->topic('ZZ Groups Filter B');
        $third = $this->topic('ZZ Groups Filter C');

        $this->upload(RecordingType::Mixed, '16513791');
        $this->upload(RecordingType::Caller, '16513791');
        $this->assignTopic('16513791', $first);

        $filter = self::topicFilter([$first, $second, $third]);

        self::assertCount(1, $this->repository->pageFor(self::STORE, 20, 0, $filter));
        self::assertSame(1, $this->repository->countFor(self::STORE, $filter));
    }

    /** **A topic or nothing yet** — the combination the control exists to make possible. */
    public function testATopicAndUnassignedTogether(): void
    {
        $first = $this->topic('ZZ Groups Filter A');
        $second = $this->topic('ZZ Groups Filter B');

        $this->upload(RecordingType::Mixed, '16513791');
        $this->upload(RecordingType::Mixed, '16513792');
        $this->upload(RecordingType::Mixed, '16513793');
        $this->assignTopic('16513791', $first);
        $this->assignTopic('16513792', $second);

        $filter = self::topicFilter([$first], true);
        $orders = array_map(
            static fn(StoreOrderGroup $group): ?string => $group->orderId,
            $this->repository->pageFor(self::STORE, 20, 0, $filter),
        );
        sort($orders);

        self::assertSame(
            ['16513791', '16513793'],
            $orders,
            'the one with the chosen topic, and the one with none — not the one with the other topic',
        );
        self::assertSame(2, $this->repository->countFor(self::STORE, $filter));
    }

    /** A topic belonging to another store is another store's topic, whatever its number. */
    public function testATopicChosenByIdDoesNotMatchAnotherStoresAssignment(): void
    {
        $topic = $this->topic('ZZ Groups Filter A');

        $this->upload(RecordingType::Mixed, '16513791');
        $this->assignTopic('16513791', $topic, self::OTHER_STORE);

        self::assertCount(0, $this->repository->pageFor(self::STORE, 20, 0, self::topicFilter([$topic])));
    }

    /** Paging happens over the matched set, and the count agrees with it. */
    public function testSeveralTopicsPageByGroup(): void
    {
        $first = $this->topic('ZZ Groups Filter A');
        $second = $this->topic('ZZ Groups Filter B');

        foreach (['16513791', '16513792', '16513793'] as $index => $orderId) {
            $this->upload(RecordingType::Mixed, $orderId);
            $this->assignTopic($orderId, $index === 2 ? $second : $first);
        }

        $filter = self::topicFilter([$first, $second]);

        self::assertSame(3, $this->repository->countFor(self::STORE, $filter));
        self::assertCount(2, $this->repository->pageFor(self::STORE, 2, 0, $filter));
        self::assertCount(1, $this->repository->pageFor(self::STORE, 2, 2, $filter));
    }

    /** A topic nobody is using matches nothing, rather than everything. */
    public function testAnUnusedTopicMatchesNothing(): void
    {
        $used = $this->topic('ZZ Groups Filter A');
        $unused = $this->topic('ZZ Groups Filter B');

        $this->upload(RecordingType::Mixed, '16513791');
        $this->assignTopic('16513791', $used);

        self::assertCount(0, $this->repository->pageFor(self::STORE, 20, 0, self::topicFilter([$unused])));
    }

    // ------------------------------------------------------------------ practisable orders only

    /**
     * **What the agent surface lists.**
     *
     * The same predicate that decides whether a store is visible at all, applied one row further down:
     * the order must exist in THIS store and the recording must still have its audio. A store qualifies
     * on the strength of one good recording, so without this it then showed every recording it had.
     */
    public function testLinkedOrdersOnlyKeepsOrdersThatExistInThisStore(): void
    {
        $this->upload(RecordingType::Mixed, '16513791');
        $this->upload(RecordingType::Mixed, '16513792');

        // Mirrored for one of them only.
        $this->mirrorOrder('16513791');

        $groups = $this->repository->pageFor(self::STORE, 20, 0, OrderGroupFilter::linkedOrdersOnly());

        self::assertCount(1, $groups);
        self::assertSame('16513791', $groups[0]->orderId);
    }

    /** The `#111` case: an order id typed at upload that was never mirrored anywhere. */
    public function testLinkedOrdersOnlyDropsAnOrderThatExistsNowhere(): void
    {
        $this->upload(RecordingType::Mixed, '111');

        self::assertCount(0, $this->repository->pageFor(self::STORE, 20, 0, OrderGroupFilter::linkedOrdersOnly()));
        self::assertSame(0, $this->repository->countFor(self::STORE, OrderGroupFilter::linkedOrdersOnly()));
    }

    /** An upload that named no order groups under its own key and can never be practised. */
    public function testLinkedOrdersOnlyDropsAnUploadWithNoOrder(): void
    {
        $this->upload(RecordingType::Mixed, null);
        $this->upload(RecordingType::Mixed, '16513791');
        $this->mirrorOrder('16513791');

        $groups = $this->repository->pageFor(self::STORE, 20, 0, OrderGroupFilter::linkedOrdersOnly());

        self::assertCount(1, $groups);
        self::assertSame('16513791', $groups[0]->orderId);
    }

    /** The collision: the order exists, in somebody else's store. */
    public function testLinkedOrdersOnlyDropsAnOrderMirroredToAnotherStore(): void
    {
        $this->upload(RecordingType::Mixed, '16513791');
        $this->mirrorOrder('16513791', self::OTHER_STORE);

        self::assertCount(0, $this->repository->pageFor(self::STORE, 20, 0, OrderGroupFilter::linkedOrdersOnly()));
    }

    /** **The pager must agree.** A narrowed list with an unnarrowed count promises pages that are empty. */
    public function testTheCountAgreesWithThePageUnderLinkedOrdersOnly(): void
    {
        foreach (['16513791', '16513792', '16513793'] as $orderId) {
            $this->upload(RecordingType::Mixed, $orderId);
        }

        $this->upload(RecordingType::Mixed, '111');
        $this->upload(RecordingType::Mixed, null);
        $this->mirrorOrder('16513791');
        $this->mirrorOrder('16513792');

        $filter = OrderGroupFilter::linkedOrdersOnly();

        self::assertSame(2, $this->repository->countFor(self::STORE, $filter));
        self::assertCount(2, $this->repository->pageFor(self::STORE, 20, 0, $filter));

        // And it pages by group over the narrowed set, not over the whole one.
        self::assertCount(1, $this->repository->pageFor(self::STORE, 1, 0, $filter));
        self::assertCount(1, $this->repository->pageFor(self::STORE, 1, 1, $filter));
        self::assertCount(0, $this->repository->pageFor(self::STORE, 1, 2, $filter));
    }

    /** A recording whose audio is gone cannot be practised, however real its order is. */
    public function testLinkedOrdersOnlyDropsAnOrderWhoseAudioIsNoLongerHeld(): void
    {
        $this->upload(RecordingType::Mixed, '16513791');
        $this->mirrorOrder('16513791');
        $this->connection->createCommand()
            ->update('{{%audio_transcription_jobs}}', ['retained_audio_path' => null], [
                'public_id' => $this->createdJobs,
            ])->execute();

        self::assertCount(0, $this->repository->pageFor(self::STORE, 20, 0, OrderGroupFilter::linkedOrdersOnly()));
    }

    /** The two dimensions compose: an agent filtering by topic asks both questions at once. */
    public function testLinkedOrdersOnlyComposesWithATopicFilter(): void
    {
        $topic = $this->topic('ZZ Groups Filter A');

        $this->upload(RecordingType::Mixed, '16513791');
        $this->upload(RecordingType::Mixed, '16513792');
        $this->upload(RecordingType::Mixed, '111');
        $this->mirrorOrder('16513791');
        $this->mirrorOrder('16513792');
        $this->assignTopic('16513791', $topic);
        $this->assignTopic('111', $topic);

        $filter = self::topicFilter([$topic])->withLinkedOrdersOnly();
        $groups = $this->repository->pageFor(self::STORE, 20, 0, $filter);

        self::assertCount(1, $groups, 'the topicked-but-unmirrored order must not appear');
        self::assertSame('16513791', $groups[0]->orderId);
        self::assertSame(1, $this->repository->countFor(self::STORE, $filter));
    }

    /** **The administrator surfaces are unchanged**: without the flag, every recording is still listed. */
    public function testWithoutTheFlagEveryRecordingIsStillListed(): void
    {
        $this->upload(RecordingType::Mixed, '16513791');
        $this->upload(RecordingType::Mixed, '111');
        $this->upload(RecordingType::Mixed, null);

        self::assertCount(3, $this->repository->pageFor(self::STORE, 20));
        self::assertSame(3, $this->repository->countFor(self::STORE));
    }

    /**
     * A selection, proved to be one.
     *
     * `forTopics()` returns null when nothing was chosen, which is the right answer for a page but a
     * silent pass for a test: `pageFor(..., null)` filters nothing and every assertion about narrowing
     * would succeed for the wrong reason.
     *
     * @param list<int> $topicIds
     */
    private static function topicFilter(array $topicIds, bool $includeUnassigned = false): OrderGroupFilter
    {
        $filter = OrderGroupFilter::forTopics($topicIds, $includeUnassigned);

        self::assertNotNull($filter, 'the test meant to narrow by something');

        return $filter;
    }

    // ------------------------------------------------- which population a listing is a list of

    /*
     * `ORDER58_CASE_ORDER_PRIMARY` decides which of two overlapping populations a store listing has a
     * row for. The cases below fix the three records that tell the modes apart — an order with no
     * recording, a recording with no order, and one of each matched — and then fix the two things that
     * must not depend on the setting at all: the agent predicate, and store isolation.
     *
     * Both modes are exercised against the same seeded data wherever that is possible, because the
     * interesting claim is not "each mode works" but "the two disagree in exactly one way".
     */

    // --- true: orders are the rows ------------------------------------------------------------

    /** An order with no recording is listed. It is what the setting exists for. */
    public function testCaseOrderPrimaryListsAnOrderWithNoRecording(): void
    {
        $this->mirrorOrder('910000001');

        $groups = $this->caseOrderFirst->pageFor(self::STORE, 20);

        self::assertCount(1, $groups);
        self::assertSame('910000001', $groups[0]->orderId);
        self::assertSame('order:910000001', $groups[0]->key->value);
        self::assertSame(1, $this->caseOrderFirst->countFor(self::STORE));
    }

    /**
     * And it is listed as an order with nothing recorded, not as a broken recording.
     *
     * Every one of these is read by the template to decide what a cell says. A row that claimed to
     * have a recording and then had none would draw a player with nothing behind it.
     */
    public function testAnOrderWithNoRecordingHasNoRecordings(): void
    {
        $this->mirrorOrder('910000002');

        $group = $this->caseOrderFirst->pageFor(self::STORE, 20)[0];

        self::assertSame([], $group->primaries());
        self::assertNull($group->mixed);
        self::assertNull($group->caller);
        self::assertNull($group->callee);
        self::assertSame(0, $group->recordingCount());
        self::assertFalse($group->hasReviewableRecording(), 'there is nothing to open');
        self::assertFalse($group->hasAnyTranscript());
        self::assertFalse($group->isSettling(), 'and nothing to wait for');
        self::assertNull($group->callTimeRaw, 'no recording means no recorded call time');
    }

    /** An order that does have recordings shows them, on the one row the order gets. */
    public function testCaseOrderPrimaryAttachesTheRecordingsToTheOrder(): void
    {
        $this->upload(RecordingType::Mixed, '910000003');
        $this->upload(RecordingType::Caller, '910000003');
        $this->mirrorOrder('910000003');

        $groups = $this->caseOrderFirst->pageFor(self::STORE, 20);

        self::assertCount(1, $groups, 'two recordings of one order are one row');
        self::assertSame(1, $this->caseOrderFirst->countFor(self::STORE), 'and one row in the total');
        self::assertNotNull($groups[0]->mixed);
        self::assertNotNull($groups[0]->caller);
    }

    /**
     * A recording whose order is not in the mirror is **not** listed in this mode.
     *
     * There is no order for it to be a row about. It stays reachable from the recording screens,
     * whose subject is the recording rather than the order.
     */
    public function testCaseOrderPrimaryExcludesARecordingWithNoOrder(): void
    {
        $this->upload(RecordingType::Mixed, '910000004');

        self::assertSame([], $this->caseOrderFirst->pageFor(self::STORE, 20));
        self::assertSame(0, $this->caseOrderFirst->countFor(self::STORE));
    }

    /** Including an upload that named no order at all, which can never match one. */
    public function testCaseOrderPrimaryExcludesAnUploadWithNoOrderId(): void
    {
        $this->upload(RecordingType::Mixed, null);

        self::assertSame([], $this->caseOrderFirst->pageFor(self::STORE, 20));
        self::assertSame(0, $this->caseOrderFirst->countFor(self::STORE));
    }

    // --- false: recordings are the rows, exactly as before --------------------------------------

    /** The recording with no order IS listed in the other mode, which is what it did before. */
    public function testRecordingPrimaryListsARecordingWithNoOrder(): void
    {
        $this->upload(RecordingType::Mixed, '910000005');

        $groups = $this->repository->pageFor(self::STORE, 20);

        self::assertCount(1, $groups);
        self::assertSame('910000005', $groups[0]->orderId);
        self::assertSame(1, $this->repository->countFor(self::STORE));
    }

    /** And the order with no recording is not. */
    public function testRecordingPrimaryExcludesAnOrderWithNoRecording(): void
    {
        $this->mirrorOrder('910000006');

        self::assertSame([], $this->repository->pageFor(self::STORE, 20));
        self::assertSame(0, $this->repository->countFor(self::STORE));
    }

    /**
     * The one disagreement, on one seeded store, in one test.
     *
     * Three records: an order nobody recorded, a recording whose order was never imported, and a
     * matched pair. Each mode shows the match plus its own population, and neither shows the other's.
     * If a change ever makes both modes agree, this is the case that says so.
     */
    public function testTheTwoModesDifferOnlyInWhichUnmatchedRecordsTheyShow(): void
    {
        $this->mirrorOrder('910000007');                      // order only
        $this->upload(RecordingType::Mixed, '910000008');     // recording only
        $this->upload(RecordingType::Mixed, '910000009');     // both
        $this->mirrorOrder('910000009');

        self::assertSame(
            ['order:910000009', 'order:910000007'],
            $this->keysOf($this->caseOrderFirst->pageFor(self::STORE, 20)),
            'orders: the matched one and the one with no recording',
        );
        self::assertSame(2, $this->caseOrderFirst->countFor(self::STORE));

        self::assertSame(
            ['order:910000009', 'order:910000008'],
            $this->keysOf($this->repository->pageFor(self::STORE, 20)),
            'recordings: the matched one and the one with no order',
        );
        self::assertSame(2, $this->repository->countFor(self::STORE));
    }

    // --- the agent predicate, which the setting must not touch ----------------------------------

    /**
     * **The agent practice surface requires an order AND its audio, in both modes.**
     *
     * Run twice against identical data, once through each configured mode, because the requirement is
     * about the *setting* having no effect here. `requireLinkedOrder` is an authorization boundary; a
     * display setting that could widen it would be a display setting that grants access.
     *
     * Four records, one per quadrant of "order?" × "audio?", and only the full one is admitted.
     */
    public function testTheAgentSurfaceRequiresBothWhicheverModeIsConfigured(): void
    {
        $this->upload(RecordingType::Mixed, '910000080');     // both
        $this->mirrorOrder('910000080');
        $this->mirrorOrder('910000081');                      // order, no audio
        $this->upload(RecordingType::Mixed, '910000082');     // audio, no order
        // The fourth quadrant — neither — is every order number nobody has mentioned.

        $filter = OrderGroupFilter::linkedOrdersOnly();

        foreach (['case-order-primary' => $this->caseOrderFirst, 'recording-primary' => $this->repository] as $mode => $repository) {
            self::assertSame(
                ['order:910000080'],
                $this->keysOf($repository->pageFor(self::STORE, 20, 0, $filter)),
                'under ' . $mode . ', only the order whose audio is in hand',
            );

            self::assertSame(
                1,
                $repository->countFor(self::STORE, $filter),
                'and under ' . $mode . ' the count agrees with the page',
            );
        }
    }

    /** An order whose audio is no longer held is not practisable either, in both modes. */
    public function testTheAgentSurfaceStillDropsAnOrderWhoseAudioIsGone(): void
    {
        $this->upload(RecordingType::Mixed, '910000085');
        $this->mirrorOrder('910000085');

        // Retention took the file. The conversation and its job remain; the audio does not.
        $this->connection->createCommand()
            ->update('{{%audio_transcription_jobs}}', ['retained_audio_path' => null], [
                'public_id' => $this->createdJobs,
            ])->execute();

        $filter = OrderGroupFilter::linkedOrdersOnly();

        self::assertSame([], $this->caseOrderFirst->pageFor(self::STORE, 20, 0, $filter));
        self::assertSame([], $this->repository->pageFor(self::STORE, 20, 0, $filter));
        self::assertSame(0, $this->caseOrderFirst->countFor(self::STORE, $filter));
        self::assertSame(0, $this->repository->countFor(self::STORE, $filter));
    }

    // --- store isolation, counts and paging, in both modes --------------------------------------

    /** Another store's order is no more visible here than another store's recording. */
    public function testAnotherStoresOrderIsNotListedHere(): void
    {
        $this->mirrorOrder('910000040', self::OTHER_STORE);

        self::assertSame(0, $this->caseOrderFirst->countFor(self::STORE));
        self::assertSame([], $this->caseOrderFirst->pageFor(self::STORE, 20));
    }

    /**
     * Paging in case-order-primary mode lists every order once, across pages.
     *
     * Five orders, one of which has two recordings. The recorded order must not consume two rows, or
     * one order would be pushed off the last page and the total would disagree with what is listed.
     */
    public function testCaseOrderPrimaryPagesEveryOrderExactlyOnce(): void
    {
        foreach (['910000060', '910000061', '910000062', '910000063', '910000064'] as $orderId) {
            $this->mirrorOrder($orderId);
        }

        $this->upload(RecordingType::Mixed, '910000062');
        $this->upload(RecordingType::Caller, '910000062');

        self::assertSame(5, $this->caseOrderFirst->countFor(self::STORE));

        $seen = [];

        for ($page = 0; $page < 3; $page++) {
            foreach ($this->caseOrderFirst->pageFor(self::STORE, 2, $page * 2) as $group) {
                $seen[] = $group->key->value;
            }
        }

        self::assertCount(5, $seen, 'five orders across three pages of two');
        self::assertSame($seen, array_values(array_unique($seen)), 'and none of them twice');
    }

    /** The topic filter narrows the order-primary query too, or a filtered page would hide rows. */
    public function testTheTopicFilterNarrowsTheCaseOrderPrimaryListing(): void
    {
        $topicId = $this->topic('Groups case order topic');

        $this->mirrorOrder('910000090');
        $this->mirrorOrder('910000091');
        $this->assignTopic('910000090', $topicId);

        $filter = self::topicFilter([$topicId]);

        $groups = $this->caseOrderFirst->pageFor(self::STORE, 20, 0, $filter);

        self::assertCount(1, $groups);
        self::assertSame('910000090', $groups[0]->orderId);
        self::assertSame(1, $this->caseOrderFirst->countFor(self::STORE, $filter));
    }

    /** And "not assigned" reaches them, which is the filter that exists to find work to do. */
    public function testUnassignedReachesOrdersWithNoRecording(): void
    {
        $topicId = $this->topic('Groups case order unassigned');

        $this->mirrorOrder('910000100');
        $this->mirrorOrder('910000101');
        $this->assignTopic('910000100', $topicId);

        $groups = $this->caseOrderFirst->pageFor(self::STORE, 20, 0, self::topicFilter([], true));

        self::assertCount(1, $groups);
        self::assertSame('910000101', $groups[0]->orderId, 'the one with no topic yet');
    }

    /**
     * `groupFor()` answers nothing for an order with no recording, in either mode.
     *
     * It is what the row's modals resolve through — the transcript and recordings dialogs — and they
     * have nothing to show for a row with no recording. Answering with an empty group would open an
     * empty dialog instead of the 404 those endpoints already give for a key with nothing behind it.
     */
    public function testGroupForStillResolvesNothingForAnOrderWithNoRecording(): void
    {
        $this->mirrorOrder('910000110');

        $key = GroupKey::fromInput('order:910000110');
        self::assertNotNull($key);

        self::assertNull($this->caseOrderFirst->groupFor(self::STORE, $key));
        self::assertNull($this->repository->groupFor(self::STORE, $key));
    }

    /**
     * An order number typed with leading zeros is its own recording row, and its order is its own row.
     *
     * Measured rather than assumed, because the opposite is the intuitive guess. `order_id` is free
     * text an operator typed, and `GroupKey` keys a group by that text — so `0910000030` is a
     * different group from `910000030`, and was before any of this existed. Each mode therefore lists
     * the record it is a list of, and the pair is visible across the two: a recording whose number
     * matches no order, and an order with no recording.
     */
    public function testAnOrderIdTypedWithLeadingZerosDoesNotMatchItsOrder(): void
    {
        $this->upload(RecordingType::Mixed, '0910000030');
        $this->mirrorOrder('910000030');

        self::assertSame(
            ['order:0910000030'],
            $this->keysOf($this->repository->pageFor(self::STORE, 20)),
        );

        self::assertSame(
            ['order:910000030'],
            $this->keysOf($this->caseOrderFirst->pageFor(self::STORE, 20)),
        );
    }

    /** @param list<StoreOrderGroup> $groups @return list<string> */
    private function keysOf(array $groups): array
    {
        return array_map(static fn(StoreOrderGroup $group): string => $group->key->value, $groups);
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
        for ($n = 1; $n <= 20; $n++) {
            $this->upload(RecordingType::Mixed, '9000000' . str_pad((string) $n, 2, '0', STR_PAD_LEFT));
        }

        /*
         * Both measurements are of a FULL page, and only the number of rows on it differs.
         *
         * That is the whole point of the test and it is worth stating, because the obvious way to write
         * it — one order in the store against twenty — varies two things at once. A page that asks for
         * twenty and is handed one is not full, and this repository asks one further constant question
         * when a page is not full: whether the store has orders with no recording at all to append (see
         * `pageFor()`). Comparing a full page against a partial one would therefore measure that
         * question rather than the per-row cost this guard exists to protect.
         *
         * So the store is seeded once and the page size is what changes. One row and twenty rows,
         * both complete pages. A loop containing a query shows up immediately: the twenty-row page
         * would cost twenty-odd statements against the one-row page's four.
         */
        $one = $this->countQueries(function (): void {
            $groups = $this->repository->pageFor(self::STORE, 1);
            self::assertCount(1, $groups);
            $this->readFully($groups);
        });

        $many = $this->countQueries(function (): void {
            $groups = $this->repository->pageFor(self::STORE, 20);
            self::assertCount(20, $groups);
            $this->readFully($groups);
        });

        self::assertSame(
            $one,
            $many,
            sprintf('One row cost %d queries and twenty cost %d — the listing is paying per row.', $one, $many),
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

    /**
     * A topic to filter by, written straight to the table.
     *
     * Deliberately not through `App\Practice`: this suite tests the AudioToText query, and reaching for
     * the other module's service here would make a filter test fail when that service changed. The name
     * is prefixed so `_after` can find it and nothing in the live table matches it.
     */
    /**
     * A mirrored order for this suite's store, so a recording naming it becomes practisable.
     *
     * Written straight to `order58_orders` rather than through the Order58 module: what is under test
     * is a correlation between two tables, not how one of them is filled.
     */
    private function mirrorOrder(string $sourceOrderId, int $store = self::STORE): void
    {
        $this->mirroredOrders[] = [$store, $sourceOrderId];

        $this->connection->createCommand()->insert('{{%order58_orders}}', [
            'account_id' => $store,
            'source_order_id' => $sourceOrderId,
            'payload_json' => '{}',
            'content_hash' => str_pad($sourceOrderId, 64, '0', STR_PAD_LEFT),
            'synced_at' => gmdate('Y-m-d H:i:s'),
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ])->execute();
    }

    private function topic(string $name): int
    {
        $this->createdTopics[] = $name;

        $this->connection->createCommand()->insert('{{%practice_topics}}', [
            'name' => $name,
            'name_key' => strtolower($name),
            'status' => 'ACTIVE',
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ])->execute();

        return (int) $this->connection->getLastInsertID();
    }

    private function assignTopic(string $sourceOrderId, int $topicId, int $store = self::STORE): void
    {
        $this->assignedOrders[] = [$store, $sourceOrderId];

        $this->connection->createCommand()->insert('{{%practice_order_topics}}', [
            'store_source_id' => $store,
            'source_order_id' => $sourceOrderId,
            'topic_id' => $topicId,
            'assigned_by_type' => 'ADMIN',
            'assigned_by_id' => $this->adminId,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ])->execute();
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
