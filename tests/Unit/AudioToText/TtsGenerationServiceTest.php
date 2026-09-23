<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\Tests\Support\Fake\AudioToText\FixedRecordingTypes;
use App\AudioToText\Domain\RecordingType;
use App\AudioToText\Application\RecordingVoiceReader;
use App\AudioToText\Application\EffectiveConversationReader;
use App\AudioToText\Application\Speaker\SpeakerSegmentsDecoder;
use App\AudioToText\Application\Tts\TtsGenerationService;
use App\AudioToText\Application\Tts\TtsScriptBuilder;
use App\AudioToText\Domain\JobStatus;
use App\AudioToText\Domain\SourceRole;
use App\AudioToText\Domain\Tts\TtsEnqueueOutcome;
use App\AudioToText\Domain\Tts\TtsException;
use App\AudioToText\Domain\Tts\TtsOutputType;
use App\Tests\Support\AudioToTextSettingsFactory;
use App\Tests\Support\Fake\AudioToText\InMemoryTtsRenditionRepository;
use App\Tests\Support\TranscriptionJobFactory;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The one place a paid generation is authorised, and the rules that keep it from being paid for twice.
 */
final class TtsGenerationServiceTest extends TestCase
{
    private InMemoryTtsRenditionRepository $renditions;
    private TtsGenerationService $service;

    protected function setUp(): void
    {
        $this->renditions = new InMemoryTtsRenditionRepository();
        $this->service = new TtsGenerationService(
            $this->renditions,
            self::builder(),
            AudioToTextSettingsFactory::create(ttsApiKey: 'test-key'),
        );
    }

    // ------------------------------------------------- the roles gate, and the trap inside it

    /**
     * **The regression this test exists for.**
     *
     * `TranscriptionJob::rolesConfirmed()` is false for every child of a separate upload, and always
     * will be: `markCompletedWithProvidedRole()` writes `speaker_separation_status => null` and never
     * sets `roles_confirmed_at`, because nothing was inferred so nothing is claimed.
     *
     * Writing the eligibility gate as `$job->rolesConfirmed()` — which reads exactly like "the roles are
     * known" — would therefore silently disable AI audio for every Customer + Agent upload ever made,
     * with no error anywhere to suggest why.
     */
    public function testASeparateChildHasKnownRolesEvenThoughRolesConfirmedIsFalse(): void
    {
        $customer = TranscriptionJobFactory::separateRecording(SourceRole::Customer);

        $this->assertFalse(
            $customer->rolesConfirmed(),
            'Guard assertion: if this ever becomes true, the trap below has gone away and this test should be revisited.',
        );
        $this->assertTrue($this->service->rolesAreKnown($customer), 'The administrator declared the role at upload.');
        $this->assertTrue($this->service->isEligible($customer));
    }

    public function testAMixedRecordingWithPublishedRolesIsEligible(): void
    {
        $job = TranscriptionJobFactory::mixedRecording(
            segments: [['role' => 'AGENT', 'speaker' => 'SPEAKER_00', 'text' => 'Ready in 25 minutes.']],
            agentText: 'Ready in 25 minutes.',
            customerText: 'Two egg foo young.',
        );

        $this->assertTrue($this->service->rolesAreKnown($job));
    }

    /**
     * A call the pipeline was not confident about shows "Speaker 1" and "Speaker 2" on screen. Choosing a
     * Customer voice for it would make exactly the claim the page declines to make, and louder.
     */
    public function testAMixedRecordingWithUnpublishedRolesIsNotEligible(): void
    {
        $job = TranscriptionJobFactory::mixedRecording(
            segments: [['role' => 'AGENT', 'speaker' => 'SPEAKER_00', 'text' => 'Ready in 25 minutes.']],
            separationStatus: null,
        );

        $this->assertFalse($this->service->rolesAreKnown($job));
        $this->assertFalse($this->service->isEligible($job));
    }

    /** A human confirmation is the other route to publishable roles. */
    public function testAConfirmedMixedRecordingIsEligible(): void
    {
        $job = TranscriptionJobFactory::mixedRecording(
            segments: [['role' => 'AGENT', 'speaker' => 'SPEAKER_00', 'text' => 'Ready in 25 minutes.']],
            agentText: 'Ready in 25 minutes.',
            customerText: 'Two egg foo young.',
            separationStatus: null,
            rolesConfirmedAt: new DateTimeImmutable('2026-09-18 12:00:00'),
        );

        $this->assertTrue($this->service->rolesAreKnown($job));
    }

    public function testAnUnfinishedRecordingIsNotEligible(): void
    {
        $job = TranscriptionJobFactory::separateRecording(SourceRole::Agent, status: JobStatus::PROCESSING);

        $this->assertFalse($this->service->isEligible($job));
    }

    // ------------------------------------------------------------------ cost protection

    public function testQueuingTwiceInARowQueuesOnce(): void
    {
        $job = $this->eligibleMixedJob();

        $this->assertSame(TtsEnqueueOutcome::Queued, $this->service->enqueue($job, TtsOutputType::Mixed, 1));
        $this->assertSame(TtsEnqueueOutcome::AlreadyRunning, $this->service->enqueue($job, TtsOutputType::Mixed, 1));
        $this->assertSame(1, $this->renditions->enqueueCount, 'A second click must not become a second charge.');
    }

    /** The automatic trigger and a manual click landing together resolve the same way. */
    public function testAnAutomaticRequestCannotStackOnTopOfAManualOne(): void
    {
        $job = $this->eligibleMixedJob();

        $this->service->enqueue($job, TtsOutputType::Mixed, 1);
        $this->service->enqueueRequested($job, true);

        $this->assertSame(1, $this->renditions->enqueueCount);
    }

    /**
     * A stale tab was labelled with text nobody has read since. Buying that is worse than refusing.
     */
    public function testAStaleFormIsRefusedWithoutQueuing(): void
    {
        $job = $this->eligibleMixedJob();

        $outcome = $this->service->enqueue($job, TtsOutputType::Mixed, 1, 'a-hash-from-an-older-render');

        $this->assertSame(TtsEnqueueOutcome::AlreadyCurrent, $outcome);
        $this->assertSame(0, $this->renditions->enqueueCount);
    }

    public function testTheCurrentHashIsAcceptedAndQueues(): void
    {
        $job = $this->eligibleMixedJob();
        $hash = $this->service->currentHash($job, TtsOutputType::Mixed);

        $this->assertSame(TtsEnqueueOutcome::Queued, $this->service->enqueue($job, TtsOutputType::Mixed, 1, $hash));
    }

    public function testNothingIsQueuedWhenTheUploadDidNotAskForIt(): void
    {
        $this->service->enqueueRequested($this->eligibleMixedJob(), false);

        $this->assertSame(0, $this->renditions->enqueueCount, 'Off by default has to mean off.');
    }

    public function testNothingIsQueuedForARecordingWhoseSpeakersAreNotConfirmed(): void
    {
        $job = TranscriptionJobFactory::mixedRecording(
            segments: [['role' => 'AGENT', 'speaker' => 'SPEAKER_00', 'text' => 'Ready in 25 minutes.']],
            separationStatus: null,
        );

        $this->assertFalse($this->service->enqueueRequested($job, true));
        $this->assertSame(0, $this->renditions->enqueueCount);
    }

    // ------------------------------------------------------------------ availability

    public function testAskingForAnOutputThisRecordingCannotProduceIsRefused(): void
    {
        $customer = TranscriptionJobFactory::separateRecording(SourceRole::Customer);

        $this->expectException(TtsException::class);

        // A separate pair has no shared clock, so a merged track would mean inventing an ordering.
        $this->service->enqueue($customer, TtsOutputType::Mixed, 1);
    }

    public function testAMixedRecordingOffersOnlyMixed(): void
    {
        $this->assertSame([TtsOutputType::Mixed], $this->service->availableTypes($this->eligibleMixedJob()));
    }

    public function testASeparatePairOffersOnlyItsOwnSide(): void
    {
        $this->assertSame(
            [TtsOutputType::Customer],
            $this->service->availableTypes(TranscriptionJobFactory::separateRecording(SourceRole::Customer)),
        );
        $this->assertSame(
            [TtsOutputType::Agent],
            $this->service->availableTypes(TranscriptionJobFactory::separateRecording(SourceRole::Agent)),
        );
    }

    public function testARecordingWithNothingToSayIsRefused(): void
    {
        $job = TranscriptionJobFactory::separateRecording(SourceRole::Agent, '');

        $this->expectException(TtsException::class);

        $this->service->enqueue($job, TtsOutputType::Agent, 1);
    }

    private function eligibleMixedJob(): \App\AudioToText\Domain\TranscriptionJob
    {
        return TranscriptionJobFactory::mixedRecording(
            segments: [
                ['role' => 'CUSTOMER', 'speaker' => 'SPEAKER_00', 'text' => 'Two egg foo young.'],
                ['role' => 'AGENT', 'speaker' => 'SPEAKER_01', 'text' => 'Ready in 25 minutes.'],
            ],
            agentText: 'Ready in 25 minutes.',
            customerText: 'Two egg foo young.',
        );
    }

    /**
     * The builder, over a recording that declared no type — every existing test's subject.
     *
     * A declared Caller or Callee recording takes a different path through `build()`, so the type has
     * to be stated rather than defaulted; {@see TtsSingleVoiceScriptTest} states the other value.
     */
    private static function builder(?RecordingType $type = null): TtsScriptBuilder
    {
        return new TtsScriptBuilder(
            new EffectiveConversationReader(new SpeakerSegmentsDecoder()),
            new RecordingVoiceReader(FixedRecordingTypes::everything($type)),
        );
    }
}
