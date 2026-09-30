<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Application\EffectiveConversationReader;
use App\AudioToText\Application\SharedConversationReader;
use App\AudioToText\Application\Speaker\SpeakerSegmentsDecoder;
use App\AudioToText\Domain\RecordingType;
use App\AudioToText\Domain\SpeakerRole;
use App\AudioToText\Domain\TranscriptionJob;
use App\AudioToText\Domain\TranscriptionJobRepositoryInterface;
use App\Tests\Support\Fake\AudioToText\LinkedConversations;
use App\Tests\Support\Fake\AudioToText\OneJobRepository;
use App\Tests\Support\TranscriptionJobFactory;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

use function array_map;

/**
 * Which conversation a Caller or Callee recording shows, and — mostly — when it shows its own.
 *
 * ## What is being protected
 *
 * A call arrives as up to three recordings, each transcribed separately. Only the mixed one has both
 * speakers in it, so only there can they be told apart, and corrections made there used to be invisible
 * on the other two. This reader lets those two borrow the mixed conversation — and nearly every test
 * below is about a case where it must **refuse** to, because a wrong borrow puts one call's words, or
 * one speaker's words, onto a page that claims they are another's.
 *
 * Nothing here writes. The borrowed turns are the mixed job's own, read through the reader that already
 * decides machine-or-reviewed, so a correction shows up on the next read with no copy to keep in step.
 */
final class SharedConversationReaderTest extends TestCase
{
    private const MIXED_JOB = 500;
    private const MIXED_CONVERSATION = 10;
    private const CHANNEL_CONVERSATION = 11;
    private const SESSION = '22449119';
    private const STORE = 831;

    /** 1. An Agent turn corrected on the mixed conversation reaches the Callee recording's view. */
    public function testTheCalleeViewShowsTheCorrectedAgentTurns(): void
    {
        $derived = $this->read(RecordingType::Callee, $this->mixedJob(edited: true));

        self::assertNotNull($derived);
        self::assertSame(SpeakerRole::AGENT, $derived->role);
        self::assertSame(['Hi.aaaa', 'Anything else?'], $this->textsOf($derived->utterances));
    }

    /** 2. And a Customer turn corrected there reaches the Caller recording's view. */
    public function testTheCallerViewShowsTheCorrectedCustomerTurns(): void
    {
        $derived = $this->read(RecordingType::Caller, $this->mixedJob(edited: true));

        self::assertNotNull($derived);
        self::assertSame(SpeakerRole::CUSTOMER, $derived->role);
        self::assertSame(['A sesame chicken combo, corrected.', 'That will be it.'], $this->textsOf($derived->utterances));
    }

    /** 3. Turns nobody corrected come through as they were, in order, with nothing dropped. */
    public function testUneditedTurnsAreUnchanged(): void
    {
        $derived = $this->read(RecordingType::Callee, $this->mixedJob(edited: false));

        self::assertNotNull($derived);
        self::assertSame(['Hi.', 'Anything else?'], $this->textsOf($derived->utterances));
    }

    /**
     * 5. Discarding the mixed corrections restores the view, with nothing to revert on this side.
     *
     * A revert clears `reviewed_segments` on the mixed job, and the next read falls back to its machine
     * segments — which is the whole argument for borrowing rather than copying: there is one copy of the
     * text, so there is one thing to undo.
     */
    public function testRevertingTheMixedCorrectionsRestoresTheDerivedView(): void
    {
        $edited = $this->read(RecordingType::Callee, $this->mixedJob(edited: true));
        $reverted = $this->read(RecordingType::Callee, $this->mixedJob(edited: false));

        self::assertSame(['Hi.aaaa', 'Anything else?'], $this->textsOf($edited?->utterances ?? []));
        self::assertSame(['Hi.', 'Anything else?'], $this->textsOf($reverted?->utterances ?? []));
    }

    /** 6. The latest reviewed version is what shows; there is no older copy anywhere to win. */
    public function testTheLatestReviewedVersionIsWhatShows(): void
    {
        $mixed = TranscriptionJobFactory::mixedRecording(
            segments: $this->machineSegments(),
            reviewedSegments: [
                ['role' => 'AGENT', 'speaker' => 'SPEAKER_01', 'text' => 'Hi.aaaa bbbb cccc', 'start_ms' => 1520, 'end_ms' => 2160],
                ['role' => 'CUSTOMER', 'speaker' => 'SPEAKER_00', 'text' => 'A sesame chicken combo.', 'start_ms' => 4400, 'end_ms' => 7680],
            ],
            rolesConfirmedAt: new DateTimeImmutable('2026-09-23 11:20:50'),
            id: self::MIXED_JOB,
            conversationId: self::MIXED_CONVERSATION,
        );

        $derived = $this->read(RecordingType::Callee, $mixed);

        self::assertSame(['Hi.aaaa bbbb cccc'], $this->textsOf($derived?->utterances ?? []));
    }

    /**
     * 7. Roles not confirmed on the mixed conversation: nothing is derived.
     *
     * A mixed recording whose speakers were never established has not decided which side is the agent,
     * so it has nothing to lend. Pushing its guess into a page that says "Agent" would turn a hypothesis
     * into a claim by moving it to a different screen.
     */
    public function testAnUnconfirmedMixedConversationLendsNothing(): void
    {
        $conversations = (new LinkedConversations())
            ->with(self::CHANNEL_CONVERSATION, RecordingType::Callee, self::SESSION)
            ->with(self::MIXED_CONVERSATION, RecordingType::Mixed, self::SESSION, self::MIXED_JOB, rolesConfirmed: false);

        self::assertNull($this->reader($conversations, $this->mixedJob(edited: true))->for($this->channelJob()));
    }

    /** 8. A recording that records no call session shows its own transcript — every row, today. */
    public function testARecordingWithNoCallSessionShowsItsOwn(): void
    {
        $conversations = (new LinkedConversations())
            ->with(self::CHANNEL_CONVERSATION, RecordingType::Callee, null)
            ->with(self::MIXED_CONVERSATION, RecordingType::Mixed, self::SESSION, self::MIXED_JOB, rolesConfirmed: true);

        self::assertNull($this->reader($conversations, $this->mixedJob(edited: true))->for($this->channelJob()));
    }

    /**
     * 9. Two calls under one order never cross-link, because the key is the call and not the order.
     *
     * Modelled the way the data models it: the other call's mixed recording is confirmed and sits at the
     * same store, and differs only in its session id. Nothing about an order is consulted at any point.
     */
    public function testAnotherCallsMixedRecordingIsNeverBorrowed(): void
    {
        $conversations = (new LinkedConversations())
            ->with(self::CHANNEL_CONVERSATION, RecordingType::Callee, self::SESSION)
            ->with(self::MIXED_CONVERSATION, RecordingType::Mixed, '99999999', self::MIXED_JOB, rolesConfirmed: true);

        self::assertNull($this->reader($conversations, $this->mixedJob(edited: true))->for($this->channelJob()));
    }

    /**
     * 11. A call with two confirmed mixed recordings derives nothing.
     *
     * Not a hypothetical: the same file uploaded twice produces it, and one store in this database holds
     * twelve copies of one recording. Picking the newest would be inventing an answer, so the page falls
     * back to the recording's own transcript and nobody is told something that might be another take.
     */
    public function testACallWithTwoConfirmedMixedRecordingsDerivesNothing(): void
    {
        $conversations = (new LinkedConversations())
            ->with(self::CHANNEL_CONVERSATION, RecordingType::Callee, self::SESSION)
            ->with(self::MIXED_CONVERSATION, RecordingType::Mixed, self::SESSION, self::MIXED_JOB, rolesConfirmed: true)
            ->with(12, RecordingType::Mixed, self::SESSION, 501, rolesConfirmed: true);

        self::assertNull($this->reader($conversations, $this->mixedJob(edited: true))->for($this->channelJob()));
    }

    /** A mixed recording is the source and never borrows, even from a call it belongs to. */
    public function testAMixedRecordingNeverBorrows(): void
    {
        $conversations = (new LinkedConversations())
            ->with(self::CHANNEL_CONVERSATION, RecordingType::Mixed, self::SESSION)
            ->with(self::MIXED_CONVERSATION, RecordingType::Mixed, self::SESSION, self::MIXED_JOB, rolesConfirmed: true);

        self::assertNull($this->reader($conversations, $this->mixedJob(edited: true))->for($this->channelJob()));
    }

    /**
     * A confirmed mixed conversation nobody has corrected still lends its machine turns.
     *
     * Confirmation is what publishes the roles, and it happens with or without an edit. Requiring a
     * reviewed layer as well would leave the commonest confirmed call showing nothing.
     */
    public function testAConfirmedButUneditedMixedConversationStillLends(): void
    {
        $mixed = TranscriptionJobFactory::mixedRecording(
            segments: $this->machineSegments(),
            agentText: 'Hi.',
            customerText: 'A sesame chicken combo.',
            rolesConfirmedAt: new DateTimeImmutable('2026-09-23 11:20:50'),
            id: self::MIXED_JOB,
            conversationId: self::MIXED_CONVERSATION,
        );

        $derived = $this->read(RecordingType::Callee, $mixed);

        self::assertSame(['Hi.', 'Anything else?'], $this->textsOf($derived?->utterances ?? []));
    }

    /** A confirmed conversation with nothing on this side falls back rather than showing an empty page. */
    public function testASideWithNoTurnsFallsBack(): void
    {
        $mixed = TranscriptionJobFactory::mixedRecording(
            segments: [['role' => 'CUSTOMER', 'speaker' => 'SPEAKER_00', 'text' => 'Only me.', 'start_ms' => 0, 'end_ms' => 900]],
            rolesConfirmedAt: new DateTimeImmutable('2026-09-23 11:20:50'),
            id: self::MIXED_JOB,
            conversationId: self::MIXED_CONVERSATION,
        );

        self::assertNull($this->read(RecordingType::Callee, $mixed));
    }

    /** The link a reader follows is the mixed job's own, so it lands where corrections are made. */
    public function testItNamesTheRecordingCorrectionsAreMadeOn(): void
    {
        $derived = $this->read(RecordingType::Callee, $this->mixedJob(edited: true));

        self::assertSame('a0652255c038ba123ae6e3d177edbbe9', $derived?->sourcePublicId);
    }

    // ---------------------------------------------------------------------------------------------

    private function read(RecordingType $type, TranscriptionJob $mixed): ?object
    {
        $conversations = (new LinkedConversations())
            ->with(self::CHANNEL_CONVERSATION, $type, self::SESSION)
            ->with(self::MIXED_CONVERSATION, RecordingType::Mixed, self::SESSION, self::MIXED_JOB, rolesConfirmed: true);

        return $this->reader($conversations, $mixed)->for($this->channelJob());
    }

    private function reader(LinkedConversations $conversations, TranscriptionJob $mixed): SharedConversationReader
    {
        return new SharedConversationReader(
            $conversations,
            $this->jobs($mixed),
            new EffectiveConversationReader(new SpeakerSegmentsDecoder()),
        );
    }

    /** The Caller or Callee recording the reader is asked about — its own transcript is never consulted. */
    private function channelJob(): TranscriptionJob
    {
        return TranscriptionJobFactory::mixedRecording(
            segments: [['role' => 'UNKNOWN', 'speaker' => 'SPEAKER_00', 'text' => "this recording's own words", 'start_ms' => 0, 'end_ms' => 500]],
            id: 700,
            conversationId: self::CHANNEL_CONVERSATION,
        );
    }

    private function mixedJob(bool $edited): TranscriptionJob
    {
        return TranscriptionJobFactory::mixedRecording(
            segments: $this->machineSegments(),
            agentText: 'Hi.',
            customerText: 'A sesame chicken combo.',
            reviewedSegments: $edited ? [
                ['role' => 'AGENT', 'speaker' => 'SPEAKER_01', 'text' => 'Hi.aaaa', 'start_ms' => 1520, 'end_ms' => 2160],
                ['role' => 'CUSTOMER', 'speaker' => 'SPEAKER_00', 'text' => 'A sesame chicken combo, corrected.', 'start_ms' => 4400, 'end_ms' => 7680],
                ['role' => 'AGENT', 'speaker' => 'SPEAKER_01', 'text' => 'Anything else?', 'start_ms' => 8640, 'end_ms' => 15280],
                ['role' => 'CUSTOMER', 'speaker' => 'SPEAKER_00', 'text' => 'That will be it.', 'start_ms' => 16125, 'end_ms' => 17725],
            ] : null,
            reviewedAgentText: $edited ? "Hi.aaaa\nAnything else?" : null,
            reviewedCustomerText: $edited ? "A sesame chicken combo, corrected.\nThat will be it." : null,
            rolesConfirmedAt: new DateTimeImmutable('2026-09-23 11:20:50'),
            id: self::MIXED_JOB,
            conversationId: self::MIXED_CONVERSATION,
        );
    }

    /** @return list<array{role: string, speaker: string, text: string, start_ms: int, end_ms: int}> */
    private function machineSegments(): array
    {
        return [
            ['role' => 'AGENT', 'speaker' => 'SPEAKER_01', 'text' => 'Hi.', 'start_ms' => 1520, 'end_ms' => 2160],
            ['role' => 'CUSTOMER', 'speaker' => 'SPEAKER_00', 'text' => 'A sesame chicken combo.', 'start_ms' => 4400, 'end_ms' => 7680],
            ['role' => 'AGENT', 'speaker' => 'SPEAKER_01', 'text' => 'Anything else?', 'start_ms' => 8640, 'end_ms' => 15280],
            ['role' => 'CUSTOMER', 'speaker' => 'SPEAKER_00', 'text' => 'That will be it.', 'start_ms' => 16125, 'end_ms' => 17725],
        ];
    }

    /** @param list<object> $utterances @return list<string> */
    private function textsOf(array $utterances): array
    {
        return array_map(static fn(object $u): string => (string) $u->text, $utterances);
    }

    private function jobs(TranscriptionJob $mixed): TranscriptionJobRepositoryInterface
    {
        return new OneJobRepository($mixed);
    }
}
