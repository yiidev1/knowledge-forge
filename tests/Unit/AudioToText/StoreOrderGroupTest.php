<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Domain\ConversationStatus;
use App\AudioToText\Domain\GroupKey;
use App\AudioToText\Domain\JobStatus;
use App\AudioToText\Domain\RecordingType;
use App\AudioToText\Domain\SourceRole;
use App\AudioToText\Domain\StoreOrderGroup;
use App\AudioToText\Domain\StoreRecordingSlot;
use App\AudioToText\Domain\TranscriptionProvider;
use Codeception\Test\Unit;
use DateTimeImmutable;

/**
 * What makes several uploads one row, and what that row then says about itself.
 *
 * Pure objects, no database: the repository's own suite covers the SQL. What is pinned here is the
 * reasoning the page depends on — the key two uploads share, the label a legacy half keeps, and the
 * rule that turns several job statuses into the one word a row shows.
 *
 * Three of these tests exist because getting them wrong would be quiet rather than loud. A collapsed
 * order-less key merges unrelated calls into one row; a dropped duplicate hides a recording that was
 * uploaded and paid for; and a status taken from whichever recording happens to be newest would report
 * a half-finished order as finished.
 */
final class StoreOrderGroupTest extends Unit
{
    // ------------------------------------------------------------------------------- the key

    public function testAnOrderIsTheKeyHoweverManyUploadsItHas(): void
    {
        $key = GroupKey::forOrder('16513791');

        self::assertSame('order:16513791', $key->value);
        self::assertSame('16513791', $key->orderId());
        self::assertTrue($key->equals(GroupKey::forOrder('16513791')));
    }

    /**
     * The case most rows in this database are in.
     *
     * Fifty of the fifty-two conversations that existed when this was written named no order. They must
     * never share a key: two unrelated recordings uploaded on different days are two rows, not one row
     * called "no order".
     */
    public function testAnUploadWithNoOrderIsKeyedByItself(): void
    {
        $first = GroupKey::forConversation(str_repeat('a', 32));
        $second = GroupKey::forConversation(str_repeat('b', 32));

        self::assertNotSame($first->value, $second->value);
        self::assertFalse($first->equals($second));
        self::assertNull($first->orderId(), 'It names a conversation, not an order.');
    }

    /**
     * @dataProvider malformedKeys
     */
    public function testAKeyThisApplicationCouldNotHaveIssuedIsRefused(string $raw): void
    {
        self::assertNull(GroupKey::fromInput($raw));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public function malformedKeys(): iterable
    {
        yield 'no namespace' => ['16513791'];
        yield 'unknown namespace' => ['store:16513791'];
        yield 'order with letters' => ['order:16513791x'];
        yield 'order with a sign' => ['order:-16513791'];
        yield 'empty order' => ['order:'];
        yield 'short conversation id' => ['conversation:' . str_repeat('a', 31)];
        yield 'uppercase conversation id' => ['conversation:' . str_repeat('A', 32)];
        yield 'trailing newline' => ["order:16513791\n"];
        yield 'sql-shaped' => ["order:1' OR '1'='1"];
    }

    /**
     * @dataProvider wellFormedKeys
     */
    public function testAWellFormedKeySurvivesTheRoundTrip(string $raw): void
    {
        $key = GroupKey::fromInput($raw);

        self::assertNotNull($key);
        self::assertSame($raw, $key->value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public function wellFormedKeys(): iterable
    {
        yield 'an order' => ['order:16513791'];
        yield 'a single digit' => ['order:1'];
        yield 'a conversation' => ['conversation:' . str_repeat('f', 32)];
    }

    /** SQL and PHP must describe the same row the same way, so both read the format from one place. */
    public function testTheSqlExpressionCarriesBothNamespaces(): void
    {
        $sql = GroupKey::sqlExpression('c');

        self::assertStringContainsString("CONCAT('order:', c.order_id)", $sql);
        self::assertStringContainsString("CONCAT('conversation:', c.public_id)", $sql);
        self::assertStringContainsString('c.order_id IS NOT NULL', $sql);
    }

    // ------------------------------------------------------------------------------ the labels

    public function testARecordingIsCalledWhatItsTypeCallsIt(): void
    {
        self::assertSame('Customer', $this->slot(RecordingType::Caller)->label());
        self::assertSame('Agent', $this->slot(RecordingType::Callee)->label());
        self::assertSame('Mix / Common', $this->slot(RecordingType::Mixed)->label());
    }

    /**
     * A legacy half is described by its own field, and now reads the same word as a caller recording.
     *
     * **This test used to assert the opposite, and the change is deliberate.** It read
     * `assertNotSame('Caller', $customer->label())`, guarding a rule that no longer holds on screen:
     * the client's operators think in Customer and Agent, so CALLER is displayed as "Customer" — which
     * is also what `SourceRole::Customer` has always displayed.
     *
     * So two different facts now share one word in the interface:
     *
     *   `source_role`     = CUSTOMER   the customer's own microphone, in a separate two-file upload
     *   `recording_type`  = CALLER     the side that dialled, in a single-file upload
     *
     * The collision is presentational and was accepted knowingly. What it must not become is a collision
     * in the code, so that is what this now asserts instead: the label a legacy half shows still comes
     * from its own `SourceRole` and nothing maps one vocabulary onto the other. A change that made
     * `StoreRecordingSlot` reach for a recording type to describe a legacy pair — inventing which side
     * placed a call nobody recorded — would still fail here.
     */
    public function testALegacyHalfIsStillDescribedByItsOwnFieldDespiteSharingTheWord(): void
    {
        $customer = $this->slot(null, SourceRole::Customer);
        $agent = $this->slot(null, SourceRole::Agent);

        self::assertSame(SourceRole::Customer->label(), $customer->label());
        self::assertSame(SourceRole::Agent->label(), $agent->label());

        // It has no recording type at all, which is the fact that matters: its label is not borrowed.
        self::assertNull($this->slot(null, SourceRole::Customer)->recordingType);

        // And the word it shows is the same one a CALLER recording shows — accepted, presentational.
        self::assertSame(RecordingType::Caller->label(), $customer->label());
        self::assertSame(RecordingType::Callee->label(), $agent->label());
    }

    // ------------------------------------------------------------- which channel a recording is

    /**
     * A recording's place in the row of tabs comes from the channel, never from the cell it landed in.
     *
     * The three named columns render in this order anyway, so for them the rank only restates what the
     * page does. It exists for the case where the page does NOT: a legacy Customer + Agent pair puts
     * both of its halves in the FIRST cell — the one headed Mix / Common — in the order their jobs were
     * inserted. Ordering the tabs by where the buttons sit would put those two under Mix and let
     * whichever half was enqueued first lead, both of which are accidents of storage.
     */
    public function testAChannelsPositionComesFromTheChannelAndNotTheCell(): void
    {
        self::assertSame(0, $this->slot(RecordingType::Mixed)->channelRank());
        self::assertSame(1, $this->slot(RecordingType::Caller)->channelRank());
        self::assertSame(2, $this->slot(RecordingType::Callee)->channelRank());

        // A legacy half has no recording type at all, so its own role answers — and lands it in the
        // same second and third places, which is where Customer and Agent belong in a row of tabs.
        self::assertSame(1, $this->slot(null, SourceRole::Customer)->channelRank());
        self::assertSame(2, $this->slot(null, SourceRole::Agent)->channelRank());
        self::assertSame(0, $this->slot(null, SourceRole::Common)->channelRank());
    }

    /**
     * Customer leads Agent whichever way round the pair was stored.
     *
     * `legacySeparate` is filled in job-id order, so a pair whose Agent half was enqueued first arrives
     * Agent-then-Customer. The rank is what makes the tabs read the same either way.
     */
    public function testALegacyPairIsOrderedCustomerThenAgentHoweverItWasStored(): void
    {
        $customer = $this->slot(null, SourceRole::Customer);
        $agent = $this->slot(null, SourceRole::Agent);

        foreach ([[$customer, $agent], [$agent, $customer]] as $stored) {
            $ranks = array_map(
                static fn(StoreRecordingSlot $slot): int => $slot->channelRank(),
                $stored,
            );

            sort($ranks);

            self::assertSame([1, 2], $ranks, 'Customer is always the first of the two.');
        }

        self::assertLessThan(
            $agent->channelRank(),
            $customer->channelRank(),
            'And never the other way round, whatever order the row rendered them in.',
        );
    }

    /**
     * Neither half of a legacy pair is ever named after the column holding it.
     *
     * Both are drawn inside the Mix / Common cell. They are described by their own `source_role`, so
     * they read Customer and Agent — and a rank that put them at 1 and 2 must not have dragged the
     * LABEL along with it.
     */
    public function testALegacyHalfIsNeverLabelledAfterTheColumnItSitsIn(): void
    {
        foreach ([SourceRole::Customer, SourceRole::Agent] as $role) {
            $half = $this->slot(null, $role);

            self::assertSame($role->label(), $half->label());
            self::assertNotSame(RecordingType::Mixed->label(), $half->label());
            self::assertNull($half->recordingType, 'It has no recording type to be named by.');
        }
    }

    // ------------------------------------------------------------------------- what a row holds

    public function testEveryRecordingOfAnOrderIsCountedIncludingTheOlderOnes(): void
    {
        $group = new StoreOrderGroup(
            GroupKey::forOrder('16513791'),
            '16513791',
            new DateTimeImmutable('2026-09-23 10:00:00'),
            mixed: $this->slot(RecordingType::Mixed),
            caller: $this->slot(RecordingType::Caller, older: [$this->slot(RecordingType::Caller)]),
            callee: $this->slot(RecordingType::Callee),
        );

        self::assertCount(3, $group->primaries(), 'Three cells, one per recording type.');
        self::assertSame(4, $group->recordingCount(), 'The second caller upload is folded, not dropped.');
        self::assertSame(1, $group->caller?->olderCount());
    }

    /** A pair uploaded before recording types existed fills the Mix / Common cell, and only that one. */
    public function testALegacyPairIsOneRowWithNoCallerOrCallee(): void
    {
        $group = new StoreOrderGroup(
            GroupKey::forConversation(str_repeat('c', 32)),
            null,
            new DateTimeImmutable('2026-09-23 10:00:00'),
            legacySeparate: [
                $this->slot(null, SourceRole::Customer),
                $this->slot(null, SourceRole::Agent),
            ],
        );

        self::assertTrue($group->isLegacySeparate());
        self::assertNull($group->caller);
        self::assertNull($group->callee);
        self::assertSame(2, $group->recordingCount());
    }

    // ------------------------------------------------------------------------------ the status

    /**
     * One word for the whole row, from the rule this application already uses.
     *
     * Delegated to {@see ConversationStatus::fromChildren()} rather than re-derived, so a row and the
     * conversion page behind it cannot disagree about whether an order is finished.
     *
     * @dataProvider statusCombinations
     *
     * @param list<JobStatus> $statuses
     */
    public function testARowsStatusIsAggregatedFromEveryRecording(
        array $statuses,
        ConversationStatus $expected,
    ): void {
        $slots = [];

        foreach ($statuses as $status) {
            $slots[] = $this->slot(RecordingType::Mixed, status: $status);
        }

        $group = new StoreOrderGroup(
            GroupKey::forOrder('16513791'),
            '16513791',
            new DateTimeImmutable('2026-09-23 10:00:00'),
            mixed: $slots[0],
            caller: $slots[1] ?? null,
            callee: $slots[2] ?? null,
        );

        self::assertSame($expected, $group->aggregateStatus());
        self::assertSame(
            ConversationStatus::fromChildren($statuses),
            $group->aggregateStatus(),
            'The row must not have a second opinion about what these statuses mean.',
        );
    }

    /**
     * @return iterable<string, array{list<JobStatus>, ConversationStatus}>
     */
    public function statusCombinations(): iterable
    {
        yield 'all queued' => [[JobStatus::QUEUED], ConversationStatus::QUEUED];
        yield 'all done' => [
            [JobStatus::COMPLETED, JobStatus::COMPLETED, JobStatus::COMPLETED],
            ConversationStatus::COMPLETED,
        ];
        yield 'one still running' => [
            [JobStatus::COMPLETED, JobStatus::PROCESSING],
            ConversationStatus::PROCESSING,
        ];
        // The combination the row exists to make visible: two of three recordings are ready, so an
        // administrator reading "Completed" would go looking for a transcript that is not there.
        yield 'one failed beside two finished' => [
            [JobStatus::COMPLETED, JobStatus::COMPLETED, JobStatus::FAILED],
            ConversationStatus::PARTIALLY_COMPLETED,
        ];
        yield 'everything failed' => [
            [JobStatus::FAILED, JobStatus::FAILED],
            ConversationStatus::FAILED,
        ];
    }

    /** An order with nothing transcribed offers no way in to a transcript. */
    public function testAnOrderWithNothingTranscribedOffersNoTranscript(): void
    {
        $group = new StoreOrderGroup(
            GroupKey::forOrder('16513791'),
            '16513791',
            new DateTimeImmutable('2026-09-23 10:00:00'),
            mixed: $this->slot(RecordingType::Mixed, status: JobStatus::QUEUED, hasTranscript: false),
        );

        self::assertFalse($group->hasAnyTranscript());

        $completed = new StoreOrderGroup(
            GroupKey::forOrder('16513791'),
            '16513791',
            new DateTimeImmutable('2026-09-23 10:00:00'),
            mixed: $this->slot(RecordingType::Mixed),
        );

        self::assertTrue($completed->hasAnyTranscript());
    }

    /**
     * @param list<StoreRecordingSlot> $older
     */
    // ---------------------------------------------------------------------------------------------
    // The status column. A mixed recording is audio-only by design, so it is not work to wait on.
    // ---------------------------------------------------------------------------------------------

    /**
     * The reported bug: a finished deterministic call read "Transcribing" forever.
     *
     * The mixed recording is NOT_REQUESTED by design and never leaves that state, and
     * {@see ConversationStatus::fromChildren()} counts NOT_REQUESTED as neither terminal nor running —
     * so the group never reached "every child finished" and fell through to PROCESSING.
     */
    public function testADeterministicCallIsFinishedWhenBothSidesAreEvenThoughTheMixIsNotRequested(): void
    {
        $group = $this->call(JobStatus::COMPLETED, JobStatus::COMPLETED);

        self::assertSame(ConversationStatus::COMPLETED, $group->aggregateStatus());
        self::assertNotSame('Transcribing', $group->aggregateStatus()->label());
    }

    /** One side still running is the only thing that makes the call still running. */
    public function testACallIsStillTranscribingWhileTheCustomerSideIs(): void
    {
        self::assertSame(
            ConversationStatus::PROCESSING,
            $this->call(JobStatus::PROCESSING, JobStatus::COMPLETED)->aggregateStatus(),
        );
    }

    public function testACallIsStillTranscribingWhileTheAgentSideIs(): void
    {
        self::assertSame(
            ConversationStatus::PROCESSING,
            $this->call(JobStatus::COMPLETED, JobStatus::PROCESSING)->aggregateStatus(),
        );
    }

    /** Failure semantics are the existing ones and are not touched by setting the mix aside. */
    public function testAFailedSideKeepsItsExistingFailureSemantics(): void
    {
        self::assertSame(
            ConversationStatus::PARTIALLY_COMPLETED,
            $this->call(JobStatus::COMPLETED, JobStatus::FAILED)->aggregateStatus(),
        );
        self::assertSame(
            ConversationStatus::FAILED,
            $this->call(JobStatus::FAILED, JobStatus::FAILED)->aggregateStatus(),
        );
    }

    /** The mix alone never moves the verdict, whatever the two sides are doing. */
    public function testTheMixedRecordingNeverDecidesTheStatus(): void
    {
        foreach ([JobStatus::COMPLETED, JobStatus::PROCESSING, JobStatus::FAILED, JobStatus::QUEUED] as $both) {
            $withMix = $this->call($both, $both);
            $withoutMix = new StoreOrderGroup(
                GroupKey::forOrder('16758551'),
                '16758551',
                new DateTimeImmutable('2026-10-06 01:32:00'),
                caller: $this->slot(RecordingType::Caller, SourceRole::Customer, $both),
                callee: $this->slot(RecordingType::Callee, SourceRole::Agent, $both),
            );

            self::assertSame(
                $withoutMix->aggregateStatus(),
                $withMix->aggregateStatus(),
                'An audio-only mix changed the verdict for ' . $both->value . '.',
            );
        }
    }

    /**
     * A side nobody has asked for yet is still outstanding, and still counts.
     *
     * This is the download-without-transcribing case: NOT_REQUESTED on a Customer or Agent recording
     * means "waiting for somebody to ask", which {@see StoreRecordingSlot::isReadyForTranscription()}
     * names, and it must not be set aside the way an audio-only mix is.
     */
    public function testASideNobodyHasAskedForIsNotTreatedAsAudioOnly(): void
    {
        self::assertSame(
            ConversationStatus::NOT_REQUESTED,
            $this->call(JobStatus::NOT_REQUESTED, JobStatus::NOT_REQUESTED)->aggregateStatus(),
            'Nothing has been asked for, so the call is ready for transcription.',
        );
    }

    /** A mixed recording that really was transcribed still counts — only NOT_REQUESTED is set aside. */
    public function testATranscribedMixedRecordingStillCounts(): void
    {
        $group = new StoreOrderGroup(
            GroupKey::forConversation(str_repeat('c', 32)),
            null,
            new DateTimeImmutable('2026-09-23 10:00:00'),
            mixed: $this->slot(RecordingType::Mixed, SourceRole::Common, JobStatus::PROCESSING),
        );

        self::assertSame(ConversationStatus::PROCESSING, $group->aggregateStatus());
    }

    /** A mixed-only upload has nothing else to go on, and reports exactly what it always did. */
    public function testAMixedOnlyUploadStillReportsItsOwnState(): void
    {
        foreach ([JobStatus::NOT_REQUESTED, JobStatus::COMPLETED, JobStatus::FAILED] as $status) {
            $group = new StoreOrderGroup(
                GroupKey::forOrder('16758551'),
                '16758551',
                new DateTimeImmutable('2026-10-06 01:32:00'),
                mixed: $this->slot(RecordingType::Mixed, SourceRole::Common, $status),
            );

            self::assertSame(
                ConversationStatus::fromChildren([$status]),
                $group->aggregateStatus(),
                'A mixed-only upload must report unchanged for ' . $status->value . '.',
            );
        }
    }

    /** A legacy Customer + Agent pair carries no mixed recording and is unaffected. */
    public function testALegacyPairIsUnaffected(): void
    {
        $group = new StoreOrderGroup(
            GroupKey::forConversation(str_repeat('c', 32)),
            null,
            new DateTimeImmutable('2026-09-23 10:00:00'),
            legacySeparate: [
                $this->slot(null, SourceRole::Customer, JobStatus::COMPLETED),
                $this->slot(null, SourceRole::Agent, JobStatus::PROCESSING),
            ],
        );

        self::assertSame(ConversationStatus::PROCESSING, $group->aggregateStatus());
    }

    /** The three-channel shape the Order58 importer produces: mix audio-only, two sides transcribed. */
    private function call(JobStatus $customer, JobStatus $agent): StoreOrderGroup
    {
        return new StoreOrderGroup(
            GroupKey::forOrder('16758551'),
            '16758551',
            new DateTimeImmutable('2026-10-06 01:32:00'),
            mixed: $this->slot(RecordingType::Mixed, SourceRole::Common, JobStatus::NOT_REQUESTED),
            caller: $this->slot(RecordingType::Caller, SourceRole::Customer, $customer),
            callee: $this->slot(RecordingType::Callee, SourceRole::Agent, $agent),
        );
    }

    private function slot(
        ?RecordingType $type,
        SourceRole $role = SourceRole::Common,
        JobStatus $status = JobStatus::COMPLETED,
        bool $hasTranscript = true,
        array $older = [],
    ): StoreRecordingSlot {
        return new StoreRecordingSlot(
            conversationPublicId: str_repeat('a', 32),
            jobPublicId: str_repeat('b', 32),
            recordingType: $type,
            sourceRole: $role,
            status: $status,
            provider: TranscriptionProvider::Whisper,
            durationSeconds: 206.3,
            uploadedAt: new DateTimeImmutable('2026-09-23 10:00:00'),
            originalFilename: 'call.wav',
            hasOriginalAudio: true,
            hasTranscript: $hasTranscript,
            hasSegments: $hasTranscript,
            rolesConfirmed: false,
            older: $older,
        );
    }
}
