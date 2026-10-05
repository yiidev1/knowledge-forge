<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Application\Combined\CombinedConversationReader;
use App\AudioToText\Application\EffectiveConversationReader;
use App\AudioToText\Application\Speaker\SpeakerSegmentsDecoder;
use App\AudioToText\Domain\JobStatus;
use App\AudioToText\Domain\RecordingType;
use App\AudioToText\Domain\Speaker\CombinedConversation;
use App\AudioToText\Domain\Speaker\CombinedConversationState;
use App\AudioToText\Domain\Speaker\CombinedTurn;
use App\AudioToText\Domain\SourceRole;
use App\AudioToText\Domain\SpeakerRole;
use App\AudioToText\Domain\TranscriptionJob;
use App\Tests\Support\Fake\AudioToText\JobsById;
use App\Tests\Support\Fake\AudioToText\LinkedConversations;
use App\Tests\Support\TranscriptionJobFactory;
use PHPUnit\Framework\TestCase;

use function array_map;

/**
 * The combined conversation a deterministic call's mixed recording shows.
 *
 * ## What is being protected
 *
 * One thing, above everything else: **a correction must land on the message it was aimed at.** A
 * combined thread is assembled from two independent transcriptions, so a message's position on screen
 * and its position inside the row that owns it are different numbers, and they disagree for most
 * messages of most calls. If the projection ever hands the display position to an edit, an
 * administrator fixing one sentence silently overwrites a different one — in a different speaker's
 * words, with no error and no trace. Tests 2 and 3 are that case, written out.
 *
 * The rest guard the reasons the projection must sometimes refuse: two recordings of one side, a side
 * that failed, a side still being transcribed, and timestamps that cannot order anything.
 */
final class CombinedConversationReaderTest extends TestCase
{
    private const SESSION = '22633299';
    private const STORE = 831;
    private const MIXED_JOB = 900;
    private const CUSTOMER_JOB = 901;
    private const AGENT_JOB = 902;
    private const MIXED_CONVERSATION = 70;
    private const CUSTOMER_CONVERSATION = 71;
    private const AGENT_CONVERSATION = 72;
    private const CUSTOMER_PUBLIC_ID = 'aaaa1111aaaa1111aaaa1111aaaa1111';
    private const AGENT_PUBLIC_ID = 'bbbb2222bbbb2222bbbb2222bbbb2222';

    /**
     * 1. Both sides are merged into one thread by the clock, not by which recording they came from.
     *
     * The spec's own example: Customer at 2s and 8s, Agent at 0s and 5s, which must read A0 C0 A1 C1.
     */
    public function testTheTwoSidesAreInterleavedByTimestamp(): void
    {
        $combined = $this->read();

        self::assertNotNull($combined);
        self::assertSame(CombinedConversationState::Complete, $combined->state);
        self::assertTrue($combined->interleaved);
        self::assertSame(
            ['A0', 'C0', 'A1', 'C1'],
            array_map(static fn(CombinedTurn $t): string => $t->text, $combined->turns),
        );
    }

    /**
     * 2. Every message keeps the index it has **inside its own recording**.
     *
     * Combined positions run 0,1,2,3. Owner-local indices run 0,0,1,1 — they are not the same numbers
     * and this is the assertion that says so.
     */
    public function testEachTurnKeepsItsOwnersLocalIndex(): void
    {
        $combined = $this->read();

        self::assertNotNull($combined);
        self::assertSame(
            [
                ['A0', SpeakerRole::AGENT->value, 0],
                ['C0', SpeakerRole::CUSTOMER->value, 0],
                ['A1', SpeakerRole::AGENT->value, 1],
                ['C1', SpeakerRole::CUSTOMER->value, 1],
            ],
            array_map(
                static fn(CombinedTurn $t): array => [$t->text, $t->role->value, $t->ownerLocalTurnIndex],
                $combined->turns,
            ),
        );
    }

    /**
     * 3. The regression the whole design exists for: editing combined #2 must touch Agent local #1.
     *
     * Combined position 2 is `A1`. Read naively, position 2 would address the Customer's third turn —
     * which in a longer call exists and holds somebody else's words. Asserted as the full address,
     * because a correction is only safe if all three parts of it are right at once.
     */
    public function testCombinedPositionTwoAddressesAgentLocalTurnOne(): void
    {
        $combined = $this->read();

        self::assertNotNull($combined);

        $turn = $combined->turns[2];

        self::assertSame('A1', $turn->text);
        self::assertSame(self::AGENT_PUBLIC_ID, $turn->ownerJobPublicId);
        self::assertSame(1, $turn->ownerLocalTurnIndex);
        self::assertSame(SpeakerRole::AGENT, $turn->role);

        // And nothing else in the thread claims that address.
        $matching = 0;
        foreach ($combined->turns as $other) {
            if ($other->ownerJobPublicId === self::AGENT_PUBLIC_ID && $other->ownerLocalTurnIndex === 1) {
                $matching++;
            }
        }

        self::assertSame(1, $matching, 'exactly one message may answer to one owner address');
    }

    /** 4. Each message carries its own owner's version, and the two sides do not share one. */
    public function testEachTurnCarriesItsOwnOwnersReviewCount(): void
    {
        $combined = $this->read(customerReviewCount: 4, agentReviewCount: 9);

        self::assertNotNull($combined);
        self::assertSame(
            [9, 4, 9, 4],
            array_map(static fn(CombinedTurn $t): int => $t->ownerReviewCount, $combined->turns),
        );
    }

    /** The owner addresses are stable under a different arrival order of the same two recordings. */
    public function testOwnershipDoesNotDependOnTheOrderTheChildrenWereFound(): void
    {
        $forwards = $this->read();
        $backwards = $this->read(agentFirst: true);

        self::assertNotNull($forwards);
        self::assertNotNull($backwards);
        self::assertSame($this->addressesOf($forwards), $this->addressesOf($backwards));
    }

    /** A corrected child's reviewed text is what the combined view shows — never the machine's. */
    public function testTheReviewedLayerOfAChildIsWhatShows(): void
    {
        $combined = $this->read(customerReviewed: [
            ['start_ms' => 2000, 'end_ms' => 3000, 'speaker' => 'CHANNEL', 'role' => 'CUSTOMER', 'text' => 'wonton soup'],
            ['start_ms' => 8000, 'end_ms' => 9000, 'speaker' => 'CHANNEL', 'role' => 'CUSTOMER', 'text' => 'C1'],
        ]);

        self::assertNotNull($combined);
        self::assertSame(
            ['A0', 'wonton soup', 'A1', 'C1'],
            array_map(static fn(CombinedTurn $t): string => $t->text, $combined->turns),
        );
    }

    /** Two recordings of one side: refused outright, with no words shown and a reason given. */
    public function testTwoRecordingsOfOneSideAreAmbiguousRatherThanGuessedAt(): void
    {
        $conversations = $this->conversations()
            ->with(73, RecordingType::Caller, self::SESSION, 903, storeSourceId: self::STORE);

        $combined = (new CombinedConversationReader(
            $conversations,
            new JobsById($this->customerJob(), $this->agentJob()),
            new EffectiveConversationReader(new SpeakerSegmentsDecoder()),
        ))->for($this->mixedJob());

        self::assertNotNull($combined);
        self::assertSame(CombinedConversationState::AmbiguousChildren, $combined->state);
        self::assertTrue($combined->isEmpty());
        self::assertNotNull($combined->state->explanation());
    }

    /** One side only: the other half is shown, and the state says which half is missing. */
    public function testACallWithOnlyTheCustomerSideSaysSo(): void
    {
        $conversations = (new LinkedConversations())
            ->with(self::MIXED_CONVERSATION, RecordingType::Mixed, self::SESSION, self::MIXED_JOB, storeSourceId: self::STORE)
            ->with(self::CUSTOMER_CONVERSATION, RecordingType::Caller, self::SESSION, self::CUSTOMER_JOB, storeSourceId: self::STORE);

        $combined = (new CombinedConversationReader(
            $conversations,
            new JobsById($this->customerJob()),
            new EffectiveConversationReader(new SpeakerSegmentsDecoder()),
        ))->for($this->mixedJob());

        self::assertNotNull($combined);
        self::assertSame(CombinedConversationState::CustomerOnly, $combined->state);
        self::assertSame(['C0', 'C1'], array_map(static fn(CombinedTurn $t): string => $t->text, $combined->turns));
        self::assertNull($combined->agent);
    }

    /** A failed side outranks a merely unfinished one, so the reader reports the actionable fault. */
    public function testAFailedSideIsReportedAheadOfAnUnfinishedOne(): void
    {
        $combined = $this->read(
            customerStatus: JobStatus::FAILED,
            agentStatus: JobStatus::PROCESSING,
        );

        self::assertNotNull($combined);
        self::assertSame(CombinedConversationState::CustomerFailed, $combined->state);
    }

    /** A side still in the queue is reported as that, and the finished side is still shown. */
    public function testAnUnfinishedSideStillShowsTheOtherOne(): void
    {
        $combined = $this->read(agentStatus: JobStatus::QUEUED, agentSegments: []);

        self::assertNotNull($combined);
        self::assertSame(CombinedConversationState::AgentProcessing, $combined->state);
        self::assertSame(['C0', 'C1'], array_map(static fn(CombinedTurn $t): string => $t->text, $combined->turns));
    }

    /**
     * A recording that was downloaded without a transcript reads as unfinished, not as failed.
     *
     * NOT_REQUESTED is a real state for a channel — the Call Recordings page stores audio without asking
     * for a transcript — and it is recoverable with one button, so it must not be reported as a failure.
     */
    public function testASideWithNoTranscriptRequestedReadsAsUnfinished(): void
    {
        $combined = $this->read(agentStatus: JobStatus::NOT_REQUESTED, agentSegments: []);

        self::assertNotNull($combined);
        self::assertSame(CombinedConversationState::AgentProcessing, $combined->state);
    }

    /** Unusable timestamps: the sides are not interleaved, and each keeps its own order. */
    public function testUnusableTimestampsFallBackToSeparateSections(): void
    {
        $zeroed = [
            ['start_ms' => 0, 'end_ms' => 0, 'speaker' => 'CHANNEL', 'role' => 'CUSTOMER', 'text' => 'C0'],
            ['start_ms' => 0, 'end_ms' => 0, 'speaker' => 'CHANNEL', 'role' => 'CUSTOMER', 'text' => 'C1'],
        ];

        $combined = $this->read(
            customerSegments: $zeroed,
            agentSegments: [
                ['start_ms' => 0, 'end_ms' => 0, 'speaker' => 'CHANNEL', 'role' => 'AGENT', 'text' => 'A0'],
            ],
        );

        self::assertNotNull($combined);
        self::assertFalse($combined->interleaved);
        self::assertSame(CombinedConversationState::InvalidTimeline, $combined->state);
        // Customer section first, then the Agent's — each in its own recording's order.
        self::assertSame(['C0', 'C1', 'A0'], array_map(static fn(CombinedTurn $t): string => $t->text, $combined->turns));
        // And the addresses still hold, which is what makes a sectioned render editable.
        self::assertSame([0, 1, 0], array_map(static fn(CombinedTurn $t): int => $t->ownerLocalTurnIndex, $combined->turns));
    }

    /** Overlapping speech is kept as it was measured; nothing is nudged to make the thread tidy. */
    public function testOverlappingSpeechIsKeptRatherThanShifted(): void
    {
        $combined = $this->read(agentSegments: [
            // Starts before the Customer's first turn ends.
            ['start_ms' => 1500, 'end_ms' => 4000, 'speaker' => 'CHANNEL', 'role' => 'AGENT', 'text' => 'A0'],
        ], customerSegments: [
            ['start_ms' => 1000, 'end_ms' => 3000, 'speaker' => 'CHANNEL', 'role' => 'CUSTOMER', 'text' => 'C0'],
        ]);

        self::assertNotNull($combined);
        self::assertSame(['C0', 'A0'], array_map(static fn(CombinedTurn $t): string => $t->text, $combined->turns));
        self::assertSame([1000, 1500], array_map(static fn(CombinedTurn $t): int => $t->startMs, $combined->turns));
        self::assertSame([3000, 4000], array_map(static fn(CombinedTurn $t): int => $t->endMs, $combined->turns));
    }

    /**
     * A mixed recording belonging to no group at all still gets an answer, not a refusal.
     *
     * It has no call session and no order, so there is nothing it could ever be combined with — but it
     * is never transcribed either, so "no words here" is its settled state rather than a fault. The
     * dialog needs its audio and a sentence, and a null would leave it with neither.
     */
    public function testAMixedRecordingWithNoGroupReportsNoChildrenRatherThanRefusing(): void
    {
        $conversations = (new LinkedConversations())
            ->with(self::MIXED_CONVERSATION, RecordingType::Mixed, null, self::MIXED_JOB, storeSourceId: self::STORE);

        $combined = (new CombinedConversationReader(
            $conversations,
            new JobsById(),
            new EffectiveConversationReader(new SpeakerSegmentsDecoder()),
        ))->for($this->mixedJob());

        self::assertNotNull($combined);
        self::assertSame(CombinedConversationState::NoChildren, $combined->state);
        self::assertNotNull($combined->state->explanation());
    }

    /**
     * A manual upload groups by its order, which is how an operator builds a set with "+ Add audio".
     *
     * Only the importer writes a call session, so without this a mixed recording added by hand could
     * never show a combined conversation however many sides were uploaded beside it.
     */
    public function testAManualUploadGroupsByItsOrder(): void
    {
        $conversations = (new LinkedConversations())
            ->with(self::MIXED_CONVERSATION, RecordingType::Mixed, null, self::MIXED_JOB, storeSourceId: self::STORE, orderId: '16513791')
            ->with(self::CUSTOMER_CONVERSATION, RecordingType::Caller, null, self::CUSTOMER_JOB, storeSourceId: self::STORE, orderId: '16513791')
            ->with(self::AGENT_CONVERSATION, RecordingType::Callee, null, self::AGENT_JOB, storeSourceId: self::STORE, orderId: '16513791');

        $combined = (new CombinedConversationReader(
            $conversations,
            new JobsById($this->customerJob(), $this->agentJob()),
            new EffectiveConversationReader(new SpeakerSegmentsDecoder()),
        ))->for($this->mixedJob());

        self::assertNotNull($combined);
        self::assertSame(CombinedConversationState::Complete, $combined->state);
        self::assertSame(
            ['A0', 'C0', 'A1', 'C1'],
            array_map(static fn(CombinedTurn $t): string => $t->text, $combined->turns),
        );
    }

    /**
     * A call session wins over the order whenever there is one.
     *
     * An order can hold more than one call — two customers ringing about the same order number — so
     * preferring the order would merge two conversations into one thread. Here the sides record a
     * different session from the mixed recording, and the result is that it finds neither of them.
     */
    public function testACallSessionIsPreferredOverTheOrder(): void
    {
        $conversations = (new LinkedConversations())
            ->with(self::MIXED_CONVERSATION, RecordingType::Mixed, self::SESSION, self::MIXED_JOB, storeSourceId: self::STORE, orderId: '16513791')
            ->with(self::CUSTOMER_CONVERSATION, RecordingType::Caller, 'a-different-call', self::CUSTOMER_JOB, storeSourceId: self::STORE, orderId: '16513791')
            ->with(self::AGENT_CONVERSATION, RecordingType::Callee, 'a-different-call', self::AGENT_JOB, storeSourceId: self::STORE, orderId: '16513791');

        $combined = (new CombinedConversationReader(
            $conversations,
            new JobsById($this->customerJob(), $this->agentJob()),
            new EffectiveConversationReader(new SpeakerSegmentsDecoder()),
        ))->for($this->mixedJob());

        self::assertNotNull($combined);
        self::assertSame(CombinedConversationState::NoChildren, $combined->state);
    }

    /** A channel recording is a source here, never a borrower: asking for its projection answers null. */
    public function testAChannelRecordingIsNotProjected(): void
    {
        $reader = new CombinedConversationReader(
            $this->conversations(),
            new JobsById($this->customerJob(), $this->agentJob()),
            new EffectiveConversationReader(new SpeakerSegmentsDecoder()),
        );

        self::assertNull($reader->for($this->customerJob()));
    }

    /** A linked mixed recording whose call has no channels at all says so rather than showing nothing. */
    public function testALinkedMixedRecordingWithNoChannelsReportsNoChildren(): void
    {
        $conversations = (new LinkedConversations())
            ->with(self::MIXED_CONVERSATION, RecordingType::Mixed, self::SESSION, self::MIXED_JOB, storeSourceId: self::STORE);

        $combined = (new CombinedConversationReader(
            $conversations,
            new JobsById(),
            new EffectiveConversationReader(new SpeakerSegmentsDecoder()),
        ))->for($this->mixedJob());

        self::assertNotNull($combined);
        self::assertSame(CombinedConversationState::NoChildren, $combined->state);
        self::assertFalse($combined->hasChildren());
    }

    /**
     * The merge verdict on a message is its owner's, not the screen's.
     *
     * `C0` and `C1` are adjacent inside the Customer recording even though an Agent turn sits between
     * them on screen, so joining them is allowed — and the thing above `C1` visually is not what would
     * be joined to it.
     */
    public function testMergeAvailabilityFollowsTheOwnersOwnNeighbours(): void
    {
        $combined = $this->read();

        self::assertNotNull($combined);

        // Combined position 3 is C1; its owner-local predecessor is C0, same role, so a merge is offered.
        self::assertSame(1, $combined->turns[3]->ownerLocalTurnIndex);
        self::assertTrue($combined->turns[3]->mergeWithPrevious->isAllowed());
        // And C0 — combined position 1 — has no owner-local predecessor at all.
        self::assertFalse($combined->turns[1]->mergeWithPrevious->isAllowed());
    }

    /** Labels and sides come from the declared role, so no combined view can print "Speaker 1". */
    public function testLabelsAreTheDeclaredRolesAndNeverNeutral(): void
    {
        $combined = $this->read();

        self::assertNotNull($combined);
        self::assertSame(
            ['Agent', 'Customer', 'Agent', 'Customer'],
            array_map(static fn(CombinedTurn $t): string => $t->label(), $combined->turns),
        );
    }

    /**
     * The pause between the two sides is measured across the merged thread.
     *
     * Neither recording contains it: the Customer file holds only the Customer, so the gap between the
     * Agent finishing and the Customer answering exists only once the two are put together.
     */
    public function testTheReplyDelayIsMeasuredAcrossTheMergedThread(): void
    {
        $combined = $this->read();

        self::assertNotNull($combined);
        // C0 starts at 2000 and A0 ended at 1000 — a one-second reply the channels cannot see alone.
        self::assertSame(1000, $combined->turns[1]->timing->delayMs);
    }

    /**
     * No message is ever offered a merge across the two recordings.
     *
     * Checked as a property of the whole thread rather than on one example, because this is the merge
     * mistake that would be invisible: joining two visually adjacent bubbles that belong to different
     * files cannot be expressed through the existing route at all — it merges turn N with turn N±1 of
     * **one** job — so a UI that offered it would produce a request that silently joined the wrong two
     * messages inside one recording.
     */
    public function testNoMergeIsEverOfferedAcrossTheTwoRecordings(): void
    {
        $combined = $this->read();

        self::assertNotNull($combined);

        foreach ($combined->turns as $position => $turn) {
            $owner = $combined->childOwning($turn->ownerJobPublicId);

            self::assertNotNull($owner, 'every message names a side of this call');

            if ($turn->mergeWithPrevious->isAllowed()) {
                self::assertArrayHasKey(
                    $turn->ownerLocalTurnIndex - 1,
                    $owner->turns->turns,
                    'a permitted merge must have a neighbour inside its own recording',
                );
            }

            if ($turn->mergeWithNext->isAllowed()) {
                self::assertArrayHasKey(
                    $turn->ownerLocalTurnIndex + 1,
                    $owner->turns->turns,
                    'a permitted merge must have a neighbour inside its own recording',
                );
            }

            $visualPrevious = $combined->turns[$position - 1] ?? null;

            if (
                $visualPrevious !== null
                && $turn->mergeWithPrevious->isAllowed()
                && $visualPrevious->ownerJobPublicId !== $turn->ownerJobPublicId
            ) {
                $hazardPresent = true;
            }
        }

        // The loop above is only worth running if the dangerous arrangement actually occurs in it: a
        // message whose merge is permitted while the bubble above it on screen belongs to the other
        // recording. Without this the test would pass just as happily on a thread where visual and
        // owner-local adjacency never diverged — which is to say, on a thread that proves nothing.
        self::assertTrue(
            $hazardPresent ?? false,
            'the fixture must contain a permitted merge whose visual neighbour is the other recording',
        );
    }

    /** No projected message ever carries the mixed recording's own version, which locks nothing. */
    public function testNoTurnCarriesTheMixedRecordingsOwnAddress(): void
    {
        $mixed = $this->mixedJob();
        $combined = $this->read(customerReviewCount: 3, agentReviewCount: 5);

        self::assertNotNull($combined);
        self::assertNotSame([], $combined->turns);

        foreach ($combined->turns as $turn) {
            self::assertNotSame($mixed->publicId, $turn->ownerJobPublicId);
            self::assertContains($turn->ownerReviewCount, [3, 5]);
        }
    }

    /**
     * @param list<array<string, mixed>>|null $customerSegments
     * @param list<array<string, mixed>>|null $agentSegments
     * @param list<array<string, mixed>>|null $customerReviewed
     */
    private function read(
        ?array $customerSegments = null,
        ?array $agentSegments = null,
        ?array $customerReviewed = null,
        JobStatus $customerStatus = JobStatus::COMPLETED,
        JobStatus $agentStatus = JobStatus::COMPLETED,
        int $customerReviewCount = 0,
        int $agentReviewCount = 0,
        bool $agentFirst = false,
    ): ?CombinedConversation {
        $customer = $this->customerJob($customerSegments, $customerReviewed, $customerStatus, $customerReviewCount);
        $agent = $this->agentJob($agentSegments, $agentStatus, $agentReviewCount);

        return (new CombinedConversationReader(
            $this->conversations($agentFirst),
            $agentFirst ? new JobsById($agent, $customer) : new JobsById($customer, $agent),
            new EffectiveConversationReader(new SpeakerSegmentsDecoder()),
        ))->for($this->mixedJob());
    }

    private function conversations(bool $agentFirst = false): LinkedConversations
    {
        $conversations = (new LinkedConversations())
            ->with(self::MIXED_CONVERSATION, RecordingType::Mixed, self::SESSION, self::MIXED_JOB, storeSourceId: self::STORE);

        $customer = static fn(LinkedConversations $c): LinkedConversations => $c->with(
            self::CUSTOMER_CONVERSATION,
            RecordingType::Caller,
            self::SESSION,
            self::CUSTOMER_JOB,
            storeSourceId: self::STORE,
        );

        $agent = static fn(LinkedConversations $c): LinkedConversations => $c->with(
            self::AGENT_CONVERSATION,
            RecordingType::Callee,
            self::SESSION,
            self::AGENT_JOB,
            storeSourceId: self::STORE,
        );

        return $agentFirst ? $customer($agent($conversations)) : $agent($customer($conversations));
    }

    private function mixedJob(): TranscriptionJob
    {
        // NOT_REQUESTED and no segments of its own: the state the policy now puts a complete group's
        // mixed recording into. It keeps the audio and nothing else.
        return TranscriptionJobFactory::mixedRecording(
            segments: null,
            transcript: null,
            status: JobStatus::NOT_REQUESTED,
            separationStatus: null,
            id: self::MIXED_JOB,
            publicId: 'cccc3333cccc3333cccc3333cccc3333',
            conversationId: self::MIXED_CONVERSATION,
        );
    }

    /**
     * @param list<array<string, mixed>>|null $segments
     * @param list<array<string, mixed>>|null $reviewed
     */
    private function customerJob(
        ?array $segments = null,
        ?array $reviewed = null,
        JobStatus $status = JobStatus::COMPLETED,
        int $reviewCount = 0,
    ): TranscriptionJob {
        return TranscriptionJobFactory::channelRecording(
            role: SourceRole::Customer,
            segments: $segments ?? [
                ['start_ms' => 2000, 'end_ms' => 3000, 'speaker' => 'CHANNEL', 'role' => 'CUSTOMER', 'text' => 'C0'],
                ['start_ms' => 8000, 'end_ms' => 9000, 'speaker' => 'CHANNEL', 'role' => 'CUSTOMER', 'text' => 'C1'],
            ],
            reviewedSegments: $reviewed,
            status: $status,
            id: self::CUSTOMER_JOB,
            publicId: self::CUSTOMER_PUBLIC_ID,
            conversationId: self::CUSTOMER_CONVERSATION,
            reviewCount: $reviewCount,
        );
    }

    /**
     * @param list<array<string, mixed>>|null $segments
     */
    private function agentJob(
        ?array $segments = null,
        JobStatus $status = JobStatus::COMPLETED,
        int $reviewCount = 0,
    ): TranscriptionJob {
        return TranscriptionJobFactory::channelRecording(
            role: SourceRole::Agent,
            segments: $segments ?? [
                ['start_ms' => 0, 'end_ms' => 1000, 'speaker' => 'CHANNEL', 'role' => 'AGENT', 'text' => 'A0'],
                ['start_ms' => 5000, 'end_ms' => 6000, 'speaker' => 'CHANNEL', 'role' => 'AGENT', 'text' => 'A1'],
            ],
            status: $status,
            id: self::AGENT_JOB,
            publicId: self::AGENT_PUBLIC_ID,
            conversationId: self::AGENT_CONVERSATION,
            reviewCount: $reviewCount,
        );
    }

    /**
     * @return list<array{string, int}>
     */
    private function addressesOf(CombinedConversation $combined): array
    {
        return array_map(
            static fn(CombinedTurn $t): array => [$t->ownerJobPublicId, $t->ownerLocalTurnIndex],
            $combined->turns,
        );
    }
}
