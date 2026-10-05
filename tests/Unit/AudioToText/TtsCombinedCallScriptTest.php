<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Application\Combined\CombinedConversationReader;
use App\AudioToText\Application\EffectiveConversationReader;
use App\AudioToText\Application\RecordingVoiceReader;
use App\AudioToText\Application\Speaker\SpeakerSegmentsDecoder;
use App\AudioToText\Application\Tts\TtsScriptBuilder;
use App\AudioToText\Application\Tts\TtsSourceDigest;
use App\AudioToText\Domain\JobStatus;
use App\AudioToText\Domain\RecordingType;
use App\AudioToText\Domain\SourceRole;
use App\AudioToText\Domain\TranscriptionJob;
use App\AudioToText\Domain\Tts\TtsOutputType;
use App\AudioToText\Domain\Tts\TtsScript;
use App\AudioToText\Domain\Tts\TtsUtterance;
use App\Tests\Support\Fake\AudioToText\JobsById;
use App\Tests\Support\Fake\AudioToText\LinkedConversations;
use App\Tests\Support\TranscriptionJobFactory;
use Codeception\Test\Unit;

use function array_map;

/**
 * What a deterministic call's mixed recording says when it is read aloud, and when that audio goes stale.
 *
 * ## Why this is not just "another script shape"
 *
 * A mixed recording of a deterministic call has `transcript` NULL and `speaker_segments` NULL — on
 * purpose, because its two channels hold the words. Everything about generated audio is keyed on the
 * content of what will be spoken: the eligibility gate, the cost estimate, and above all the digest
 * that decides whether existing audio is still current. Read from the mixed row alone, every one of
 * those answers "there is nothing here", and a call with a full transcript would offer no audio at all.
 *
 * ## The staleness requirement, stated as a test
 *
 * The audio must report itself stale when **either** side is corrected. There is no rule about children
 * anywhere in {@see TtsSourceDigest} and none is wanted: the digest hashes the ordered (role, text)
 * pairs of the script, the script is the projection, and the projection reads both children through the
 * effective layer. So correcting either one moves the digest for free — which is what the last three
 * tests here check, including that the mixed row's own `review_count` has nothing to do with it.
 */
final class TtsCombinedCallScriptTest extends Unit
{
    private const SESSION = '22633299';
    private const STORE = 831;
    private const MIXED_JOB = 900;
    private const CUSTOMER_JOB = 901;
    private const AGENT_JOB = 902;
    private const MIXED_CONVERSATION = 70;
    private const CUSTOMER_CONVERSATION = 71;
    private const AGENT_CONVERSATION = 72;

    /** The script is both sides, in the order the call happened, each in its own role's voice. */
    public function testTheScriptIsBothSidesInCallOrder(): void
    {
        $script = $this->script();

        $this->assertSame(
            [
                ['AGENT', 'Good evening, Wah Sing.'],
                ['CUSTOMER', 'One wonton soup please.'],
                ['AGENT', 'Anything else?'],
                ['CUSTOMER', 'That will be it.'],
            ],
            $this->rowsOf($script),
        );
    }

    /** Nothing is omitted: every turn of a channel carries the role its file declared. */
    public function testNoTurnIsLeftWithoutAVoice(): void
    {
        $this->assertSame(0, $this->script()->omittedTurns);
    }

    /** A recording that is not a deterministic call's mixed row produces no combined script at all. */
    public function testAnOrdinaryRecordingHasNoCombinedScript(): void
    {
        $builder = $this->builder($this->customerJob(), $this->agentJob());

        $this->assertNull($builder->combinedCallScript($this->customerJob()));
    }

    /** Correcting the Customer side changes what is spoken, and therefore the digest. */
    public function testCorrectingTheCustomerSideChangesTheDigest(): void
    {
        $before = $this->digest();
        $after = $this->digest(customerReviewed: [
            $this->segment(2000, 3000, 'CUSTOMER', 'One wonton soup please, extra chilli.'),
            $this->segment(8000, 9000, 'CUSTOMER', 'That will be it.'),
        ]);

        $this->assertNotSame($before, $after);
    }

    /** And correcting the Agent side does too — the requirement neither side may satisfy alone. */
    public function testCorrectingTheAgentSideChangesTheDigest(): void
    {
        $before = $this->digest();
        $after = $this->digest(agentReviewed: [
            $this->segment(0, 1000, 'AGENT', 'Good evening, Wah Sing Kitchen.'),
            $this->segment(5000, 6000, 'AGENT', 'Anything else?'),
        ]);

        $this->assertNotSame($before, $after);
    }

    /**
     * The mixed row's own `review_count` moving changes nothing.
     *
     * It is a counter on a row that holds no words. Pinning audio to it would raise "your transcript
     * changed" on a call where nothing was said differently, and clearing that notice costs money.
     */
    public function testTheMixedRowsOwnVersionDoesNotAffectTheDigest(): void
    {
        $this->assertSame(
            $this->digest(),
            $this->digest(mixedReviewCount: 17),
        );
    }

    /** Two identical reads of an untouched call agree, so nothing reports itself stale by accident. */
    public function testTheDigestIsStableAcrossReads(): void
    {
        $this->assertSame($this->digest(), $this->digest());
    }

    /**
     * @param list<array<string, mixed>>|null $customerReviewed
     * @param list<array<string, mixed>>|null $agentReviewed
     */
    private function script(
        ?array $customerReviewed = null,
        ?array $agentReviewed = null,
        int $mixedReviewCount = 0,
    ): TtsScript {
        $builder = $this->builder(
            $this->customerJob($customerReviewed),
            $this->agentJob($agentReviewed),
        );

        return $builder->build($this->mixedJob($mixedReviewCount), TtsOutputType::Mixed);
    }

    /**
     * @param list<array<string, mixed>>|null $customerReviewed
     * @param list<array<string, mixed>>|null $agentReviewed
     */
    private function digest(
        ?array $customerReviewed = null,
        ?array $agentReviewed = null,
        int $mixedReviewCount = 0,
    ): string {
        return TtsSourceDigest::for(
            TtsOutputType::Mixed,
            $this->script($customerReviewed, $agentReviewed, $mixedReviewCount)->utterances,
        );
    }

    private function builder(TranscriptionJob $customer, TranscriptionJob $agent): TtsScriptBuilder
    {
        $conversations = (new LinkedConversations())
            ->with(self::MIXED_CONVERSATION, RecordingType::Mixed, self::SESSION, self::MIXED_JOB, storeSourceId: self::STORE)
            ->with(self::CUSTOMER_CONVERSATION, RecordingType::Caller, self::SESSION, self::CUSTOMER_JOB, storeSourceId: self::STORE)
            ->with(self::AGENT_CONVERSATION, RecordingType::Callee, self::SESSION, self::AGENT_JOB, storeSourceId: self::STORE);

        $effective = new EffectiveConversationReader(new SpeakerSegmentsDecoder());
        $jobs = new JobsById($customer, $agent);

        return new TtsScriptBuilder(
            $effective,
            new RecordingVoiceReader($conversations),
            new CombinedConversationReader($conversations, $jobs, $effective),
        );
    }

    private function mixedJob(int $reviewCount = 0): TranscriptionJob
    {
        return TranscriptionJobFactory::mixedRecording(
            segments: null,
            transcript: null,
            status: JobStatus::NOT_REQUESTED,
            separationStatus: null,
            reviewCount: $reviewCount,
            id: self::MIXED_JOB,
            publicId: 'cccc3333cccc3333cccc3333cccc3333',
            conversationId: self::MIXED_CONVERSATION,
        );
    }

    /**
     * @param list<array<string, mixed>>|null $reviewed
     */
    private function customerJob(?array $reviewed = null): TranscriptionJob
    {
        return TranscriptionJobFactory::channelRecording(
            role: SourceRole::Customer,
            segments: [
                $this->segment(2000, 3000, 'CUSTOMER', 'One wonton soup please.'),
                $this->segment(8000, 9000, 'CUSTOMER', 'That will be it.'),
            ],
            reviewedSegments: $reviewed,
            id: self::CUSTOMER_JOB,
            publicId: 'aaaa1111aaaa1111aaaa1111aaaa1111',
            conversationId: self::CUSTOMER_CONVERSATION,
        );
    }

    /**
     * @param list<array<string, mixed>>|null $reviewed
     */
    private function agentJob(?array $reviewed = null): TranscriptionJob
    {
        return TranscriptionJobFactory::channelRecording(
            role: SourceRole::Agent,
            segments: [
                $this->segment(0, 1000, 'AGENT', 'Good evening, Wah Sing.'),
                $this->segment(5000, 6000, 'AGENT', 'Anything else?'),
            ],
            reviewedSegments: $reviewed,
            id: self::AGENT_JOB,
            publicId: 'bbbb2222bbbb2222bbbb2222bbbb2222',
            conversationId: self::AGENT_CONVERSATION,
        );
    }

    /**
     * @return array{start_ms: int, end_ms: int, speaker: string, role: string, text: string}
     */
    private function segment(int $start, int $end, string $role, string $text): array
    {
        return [
            'start_ms' => $start,
            'end_ms' => $end,
            // What the segmenter writes: one speaker, named for the channel rather than for a cluster.
            'speaker' => 'CHANNEL',
            'role' => $role,
            'text' => $text,
        ];
    }

    /**
     * @return list<array{string, string}>
     */
    private function rowsOf(TtsScript $script): array
    {
        return array_map(
            static fn(TtsUtterance $u): array => [$u->role->value, $u->text],
            $script->utterances,
        );
    }
}
