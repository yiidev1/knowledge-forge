<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Application\Speaker\CrossChannelEvidenceReader;
use App\AudioToText\Application\Speaker\SpeakerSegmentsDecoder;
use App\AudioToText\Domain\JobStatus;
use App\AudioToText\Domain\RecordingType;
use App\AudioToText\Domain\SourceRole;
use App\AudioToText\Domain\TranscriptionJob;
use App\Tests\Support\Fake\AudioToText\JobsById;
use App\Tests\Support\Fake\AudioToText\LinkedConversations;
use App\Tests\Support\TranscriptionJobFactory;
use Codeception\Test\Unit;

/**
 * Whether a recording has a *proven* opposite-channel sibling. **No model, no database.**
 *
 * Every test here is about refusing. A wrong sibling would confirm handovers from somebody else's
 * conversation and cut this one at random, so the interesting assertions are the nulls: the guard is the
 * feature, and the evidence is what is left when none of the vetoes fire.
 */
final class CrossChannelEvidenceReaderTest extends Unit
{
    private const SESSION = '22613839';
    private const STORE = 831;

    /** Two turns at two different instants — the minimum that can say when a conversation changed hands. */
    private const SIBLING_TURNS = [
        ['start_ms' => 1000, 'end_ms' => 4000, 'text' => 'Hi there.', 'speaker' => 'CHANNEL', 'role' => 'CUSTOMER', 'confidence' => 1],
        ['start_ms' => 9000, 'end_ms' => 12000, 'text' => 'Delivery please.', 'speaker' => 'CHANNEL', 'role' => 'CUSTOMER', 'confidence' => 1],
    ];

    public function testTheSecondDeterministicChannelGetsItsSiblingsTurns(): void
    {
        $evidence = $this->read();

        $this->assertNotNull($evidence);
        $this->assertSame([1000, 9000], $evidence->floorTakenAt);
        $this->assertSame([4000, 12000], $evidence->handoverAt);
    }

    /** The first channel of a call has nobody to ask. This is the one-sidedness, asserted. */
    public function testWithNoSiblingAtAllThereIsNoEvidence(): void
    {
        $this->assertNull($this->read(withSibling: false));
    }

    /** A sibling still being transcribed is not a sibling yet, and nothing waits for it. */
    public function testASiblingThatHasNotCompletedIsIgnored(): void
    {
        foreach ([JobStatus::QUEUED, JobStatus::PROCESSING, JobStatus::FAILED, JobStatus::NOT_REQUESTED] as $status) {
            $this->assertNull($this->read(siblingStatus: $status), $status->value . ' must not qualify.');
        }
    }

    /** A completed sibling whose timings were unusable stored null, and one bubble confirms nothing. */
    public function testASiblingWithNoStoredSegmentsIsIgnored(): void
    {
        $this->assertNull($this->read(siblingSegments: null));
    }

    /** Two completed rows for the same side is the ambiguous case, and ambiguity never guesses. */
    public function testTwoCompletedSiblingsForOneSideAreRefused(): void
    {
        $conversations = (new LinkedConversations())
            ->with(42, RecordingType::Callee, self::SESSION, jobId: 20, storeSourceId: self::STORE)
            ->with(43, RecordingType::Caller, self::SESSION, jobId: 10, storeSourceId: self::STORE)
            // The same channel imported twice: two completed CALLER rows, no basis for choosing.
            ->with(44, RecordingType::Caller, self::SESSION, jobId: 11, storeSourceId: self::STORE);

        $jobs = new JobsById(
            $this->sibling(10),
            $this->sibling(11, publicId: 'f1bb2d0c6e4a4f5db7c8a9e0d1f2a3b4'),
        );

        $this->assertNull($this->reader($conversations, $jobs)->for($this->current()));
    }

    /** A generic separate upload has no call session, so it never reaches the cross-channel path. */
    public function testAGenericSeparateUploadIsRefused(): void
    {
        $conversations = (new LinkedConversations())
            // No recording type and no call session: two files an administrator sent together.
            ->with(42, null, null, jobId: 20, storeSourceId: self::STORE)
            ->with(43, null, null, jobId: 10, storeSourceId: self::STORE);

        $this->assertNull($this->reader($conversations, new JobsById($this->sibling(10)))->for($this->current()));
    }

    /** A CALLER/CALLEE row with no session id is historical data, not a proven pair. Nothing is inferred. */
    public function testAChannelWithNoCallSessionIdIsRefused(): void
    {
        $conversations = (new LinkedConversations())
            ->with(42, RecordingType::Callee, null, jobId: 20, storeSourceId: self::STORE)
            ->with(43, RecordingType::Caller, null, jobId: 10, storeSourceId: self::STORE);

        $this->assertNull($this->reader($conversations, new JobsById($this->sibling(10)))->for($this->current()));
    }

    /** Legacy COMMON is diarized and this says nothing about what a diarizer should conclude. */
    public function testALegacyCommonRecordingIsRefused(): void
    {
        $current = TranscriptionJobFactory::channelRecording(
            role: SourceRole::Common,
            status: JobStatus::PROCESSING,
            id: 20,
            conversationId: 42,
        );

        $this->assertNull($this->reader($this->conversations(), new JobsById($this->sibling(10)))->for($current));
    }

    /** A mixed recording is not one side of a call, whatever role it was queued with. */
    public function testAMixedRecordingIsRefused(): void
    {
        $conversations = (new LinkedConversations())
            ->with(42, RecordingType::Mixed, self::SESSION, jobId: 20, storeSourceId: self::STORE)
            ->with(43, RecordingType::Caller, self::SESSION, jobId: 10, storeSourceId: self::STORE);

        $this->assertNull($this->reader($conversations, new JobsById($this->sibling(10)))->for($this->current()));
    }

    /**
     * The channel the file arrived on must agree with the role the job carries.
     *
     * CALLEE is the Agent. A CALLEE row holding a job queued as CUSTOMER means something re-labelled one
     * of them, and then neither can be trusted to name a side of this call.
     */
    public function testAChannelDisagreeingWithItsDeclaredRoleIsRefused(): void
    {
        $current = TranscriptionJobFactory::channelRecording(
            role: SourceRole::Customer,
            status: JobStatus::PROCESSING,
            id: 20,
            conversationId: 42,
        );

        $this->assertNull($this->reader($this->conversations(), new JobsById($this->sibling(10)))->for($current));
    }

    /** A sibling on the opposite channel whose own role was written the other way round is not trusted. */
    public function testASiblingWhoseRoleContradictsItsChannelIsIgnored(): void
    {
        $sibling = TranscriptionJobFactory::channelRecording(
            role: SourceRole::Agent,          // a CALLER row must hold the Customer
            segments: self::SIBLING_TURNS,
            id: 10,
            conversationId: 43,
        );

        $this->assertNull($this->reader($this->conversations(), new JobsById($sibling))->for($this->current()));
    }

    /** Another store's call with the same provider number is a different conversation. */
    public function testASiblingInAnotherStoreIsIgnored(): void
    {
        $conversations = (new LinkedConversations())
            ->with(42, RecordingType::Callee, self::SESSION, jobId: 20, storeSourceId: self::STORE)
            ->with(43, RecordingType::Caller, self::SESSION, jobId: 10, storeSourceId: 999);

        $this->assertNull($this->reader($conversations, new JobsById($this->sibling(10)))->for($this->current()));
    }

    /** A different call at the same store is not this call. */
    public function testASiblingFromAnotherCallSessionIsIgnored(): void
    {
        $conversations = (new LinkedConversations())
            ->with(42, RecordingType::Callee, self::SESSION, jobId: 20, storeSourceId: self::STORE)
            ->with(43, RecordingType::Caller, '22414839', jobId: 10, storeSourceId: self::STORE);

        $this->assertNull($this->reader($conversations, new JobsById($this->sibling(10)))->for($this->current()));
    }

    /** A sibling transcript stored without usable timings cannot act as a clock. */
    public function testASiblingWhoseTurnsShareOneInstantIsRefused(): void
    {
        $flat = [
            ['start_ms' => 0, 'end_ms' => 4000, 'text' => 'Hi.', 'speaker' => 'CHANNEL', 'role' => 'CUSTOMER', 'confidence' => 1],
            ['start_ms' => 0, 'end_ms' => 9000, 'text' => 'Delivery.', 'speaker' => 'CHANNEL', 'role' => 'CUSTOMER', 'confidence' => 1],
        ];

        $this->assertNull($this->read(siblingSegments: $flat));
    }

    /** It reads; it never writes. Nothing about the sibling may be touched by looking at it. */
    public function testTheSiblingIsNeverWrittenTo(): void
    {
        $conversations = $this->conversations();

        $this->assertNotNull($this->reader($conversations, new JobsById($this->sibling(10)))->for($this->current()));
        $this->assertSame([], $conversations->written, 'Reading evidence must not write a conversation.');
    }

    // -------------------------------------------------------------------------------------------------

    private function read(
        bool $withSibling = true,
        JobStatus $siblingStatus = JobStatus::COMPLETED,
        ?array $siblingSegments = self::SIBLING_TURNS,
    ): ?object {
        $conversations = (new LinkedConversations())
            ->with(42, RecordingType::Callee, self::SESSION, jobId: 20, storeSourceId: self::STORE);

        if ($withSibling) {
            $conversations->with(43, RecordingType::Caller, self::SESSION, jobId: 10, storeSourceId: self::STORE);
        }

        $jobs = $withSibling
            ? new JobsById($this->sibling(10, $siblingStatus, $siblingSegments))
            : new JobsById();

        return $this->reader($conversations, $jobs)->for($this->current());
    }

    /** The ordinary good case: a CALLEE being completed beside an already-completed CALLER. */
    private function conversations(): LinkedConversations
    {
        return (new LinkedConversations())
            ->with(42, RecordingType::Callee, self::SESSION, jobId: 20, storeSourceId: self::STORE)
            ->with(43, RecordingType::Caller, self::SESSION, jobId: 10, storeSourceId: self::STORE);
    }

    private function reader(LinkedConversations $conversations, JobsById $jobs): CrossChannelEvidenceReader
    {
        return new CrossChannelEvidenceReader($conversations, $jobs, new SpeakerSegmentsDecoder());
    }

    /** The job being completed right now: the CALLEE side, mid-processing, nothing stored yet. */
    private function current(): TranscriptionJob
    {
        return TranscriptionJobFactory::channelRecording(
            role: SourceRole::Agent,
            status: JobStatus::PROCESSING,
            id: 20,
            conversationId: 42,
        );
    }

    private function sibling(
        int $id,
        JobStatus $status = JobStatus::COMPLETED,
        ?array $segments = self::SIBLING_TURNS,
        string $publicId = 'e0cc9a7b1f2d4e3a8b5c6d7e8f901234',
    ): TranscriptionJob {
        return TranscriptionJobFactory::channelRecording(
            role: SourceRole::Customer,
            segments: $segments,
            status: $status,
            id: $id,
            publicId: $publicId,
            conversationId: 43,
        );
    }
}
