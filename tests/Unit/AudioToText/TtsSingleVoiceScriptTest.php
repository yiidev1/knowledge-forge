<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Application\EffectiveConversationReader;
use App\AudioToText\Application\RecordingVoiceReader;
use App\AudioToText\Application\Speaker\SpeakerSegmentsDecoder;
use App\AudioToText\Application\Tts\TtsGenerationService;
use App\AudioToText\Application\Tts\TtsRenderKey;
use App\AudioToText\Application\Tts\TtsScriptBuilder;
use App\AudioToText\Application\Tts\TtsSourceDigest;
use App\AudioToText\Domain\RecordingType;
use App\AudioToText\Domain\SourceRole;
use App\AudioToText\Domain\SpeakerRole;
use App\AudioToText\Domain\SpeakerSeparationStatus;
use App\AudioToText\Domain\TranscriptionJob;
use App\AudioToText\Domain\Tts\TtsOutputType;
use App\AudioToText\Domain\Tts\TtsVoice;
use App\Tests\Support\AudioToTextSettingsFactory;
use App\Tests\Support\Fake\AudioToText\FixedRecordingTypes;
use App\Tests\Support\Fake\AudioToText\InMemoryTtsRenditionRepository;
use App\Tests\Support\TranscriptionJobFactory;
use Codeception\Test\Unit;

use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Generating audio from a recording that holds one side of a call.
 *
 * ## The failure this exists to prevent
 *
 * A Caller upload is transcribed exactly like a mixed one, so the diarizer runs and returns two
 * clusters with AGENT and CUSTOMER hypotheses attached. Building a conversation from those would put
 * **two synthetic voices on one person's words** — a two-hander assembled out of a cluster boundary.
 * Every test below is a way of asking whether that happened.
 *
 * ## And the dead end it removes
 *
 * Such a recording was also permanently ineligible: `rolesAreKnown()` wanted a provided source role or
 * a human confirmation, and the confirmation itself needs a non-empty turn in both AGENT and CUSTOMER,
 * which one person's audio may never have. There was no action that would have unblocked it.
 */
final class TtsSingleVoiceScriptTest extends Unit
{
    // ------------------------------------------------------------------ one voice, whatever was found

    /**
     * @dataProvider sides
     */
    public function testEveryTurnIsSpokenInTheRecordingsOwnVoice(
        RecordingType $type,
        TtsVoice $expected,
    ): void {
        $script = $this->builder($type)->build($this->diarizedJob(), TtsOutputType::Mixed);

        self::assertCount(3, $script->utterances);

        foreach ($script->utterances as $utterance) {
            self::assertSame($expected, $utterance->voice);
        }
    }

    /**
     * @return iterable<string, array{RecordingType, TtsVoice}>
     */
    public function sides(): iterable
    {
        yield 'caller' => [RecordingType::Caller, TtsVoice::Caller];
        yield 'callee' => [RecordingType::Callee, TtsVoice::Callee];
    }

    /**
     * The clusters really are there, and really do carry both roles.
     *
     * Asserted explicitly so that a future change which started selecting voices from the role would
     * fail here rather than in a paid render nobody listens to.
     */
    public function testTheDiarizersTwoRolesSelectNothing(): void
    {
        $script = $this->builder(RecordingType::Caller)->build($this->diarizedJob(), TtsOutputType::Mixed);

        $roles = [];
        $models = [];
        $tts = AudioToTextSettingsFactory::create()->tts;

        foreach ($script->utterances as $utterance) {
            $roles[] = $utterance->role;
            $models[] = $tts->modelForVoice($utterance->voice ?? TtsVoice::Customer);
        }

        self::assertSame(
            [SpeakerRole::CUSTOMER, SpeakerRole::AGENT, SpeakerRole::CUSTOMER],
            $roles,
            'The stored roles are untouched — they are simply not what chooses a voice here.',
        );
        self::assertCount(1, array_unique($models), 'One model throughout: ' . implode(', ', $models));
    }

    /** A mixed recording is untouched: its voices still come from the published roles. */
    public function testAConversationStillUsesBothVoices(): void
    {
        $script = $this->builder(RecordingType::Mixed)->build($this->diarizedJob(), TtsOutputType::Mixed);

        foreach ($script->utterances as $utterance) {
            self::assertNull($utterance->voice, 'A conversation names no voice; the role chooses it.');
        }

        self::assertSame(
            [SpeakerRole::CUSTOMER, SpeakerRole::AGENT, SpeakerRole::CUSTOMER],
            array_map(static fn($u): SpeakerRole => $u->role, $script->utterances),
        );
    }

    // ------------------------------------------------------------------ the corrected text wins

    /** The reviewed layer, exactly as a conversation uses it: what is spoken is what is on screen. */
    public function testTheCorrectedWordingIsSpoken(): void
    {
        $job = $this->diarizedJob(reviewed: [
            ['start_ms' => 0, 'end_ms' => 3000, 'speaker' => 'SPEAKER_00', 'role' => 'CUSTOMER',
                'text' => 'Sesame chicken with fried rice.', 'confidence' => 0.9, 'approx' => false],
        ]);

        $script = $this->builder(RecordingType::Caller)->build($job, TtsOutputType::Mixed);

        self::assertCount(1, $script->utterances, 'A merge leaves one turn, and it is spoken once.');
        self::assertSame('Sesame chicken with fried rice.', $script->utterances[0]->text);
    }

    /** A recording nothing separated still speaks: its transcript is the script. */
    public function testARecordingWithNoTurnsStillSpeaksItsTranscript(): void
    {
        $job = TranscriptionJobFactory::mixedRecording(
            segments: null,
            transcript: 'One large pepperoni for pickup.',
            separationStatus: null,
        );

        $script = $this->builder(RecordingType::Caller)->build($job, TtsOutputType::Mixed);

        self::assertCount(1, $script->utterances);
        self::assertSame('One large pepperoni for pickup.', $script->utterances[0]->text);
        self::assertSame(TtsVoice::Caller, $script->utterances[0]->voice);
    }

    // ------------------------------------------------------------------ eligibility

    /** Eligible with no confirmation, because the upload already said whose side this is. */
    public function testASideRecordingIsEligibleWithoutAnyConfirmation(): void
    {
        $job = $this->diarizedJob();

        self::assertNull($job->rolesConfirmedAt, 'Nobody confirmed anything.');
        self::assertTrue($this->service(RecordingType::Caller)->isEligible($job));
        self::assertTrue($this->service(RecordingType::Callee)->isEligible($job));
    }

    /** And an ambiguous mixed recording is still blocked, exactly as before. */
    public function testAnAmbiguousConversationIsStillBlocked(): void
    {
        self::assertFalse($this->service(RecordingType::Mixed)->isEligible($this->diarizedJob()));
        self::assertFalse($this->service(null)->isEligible($this->diarizedJob()));
    }

    /** A legacy Customer or Agent half keeps its own route to eligibility, untouched. */
    public function testALegacyProvidedRoleIsStillEligible(): void
    {
        $job = TranscriptionJobFactory::separateRecording(SourceRole::Agent, 'Pickup or delivery?');

        // Declared at upload, so the reader withholds a voice and the old gate answers — which it does.
        self::assertNull($this->builder(RecordingType::Caller)->voiceFor($job));
        self::assertTrue($this->service(RecordingType::Caller)->isEligible($job));
    }

    // ------------------------------------------------------------------ staleness

    /** A correction after generation makes the stored audio stale, by the same digest mechanism. */
    public function testEditingTheTranscriptChangesTheDigest(): void
    {
        $builder = $this->builder(RecordingType::Caller);
        $before = TtsSourceDigest::for(
            TtsOutputType::Mixed,
            $builder->build($this->diarizedJob(), TtsOutputType::Mixed)->utterances,
        );

        $after = TtsSourceDigest::for(TtsOutputType::Mixed, $builder->build(
            $this->diarizedJob(reviewed: [
                ['start_ms' => 0, 'end_ms' => 3000, 'speaker' => 'SPEAKER_00', 'role' => 'CUSTOMER',
                    'text' => 'Something else entirely.', 'confidence' => 0.9, 'approx' => false],
            ]),
            TtsOutputType::Mixed,
        )->utterances);

        self::assertNotSame($before, $after);
    }

    /**
     * A single-side file depends on its own voice and on neither of the conversation's two.
     *
     * The second half matters as much as the first: without it, changing the Agent voice would
     * invalidate every Caller rendition and invite a paid regeneration of all of them.
     */
    public function testTheRenderKeyFollowsTheSideVoiceAndNothingElse(): void
    {
        $base = AudioToTextSettingsFactory::create()->tts;
        $key = TtsRenderKey::for($base, TtsOutputType::Mixed, TtsVoice::Caller);

        $otherAgent = AudioToTextSettingsFactory::create(ttsAgentModel: 'aura-2-zeus-en')->tts;
        self::assertSame($key, TtsRenderKey::for($otherAgent, TtsOutputType::Mixed, TtsVoice::Caller));

        $otherCaller = AudioToTextSettingsFactory::create(ttsCallerModel: 'aura-2-zeus-en')->tts;
        self::assertNotSame($key, TtsRenderKey::for($otherCaller, TtsOutputType::Mixed, TtsVoice::Caller));

        // And a conversation's key is unchanged by the two new settings.
        self::assertSame(
            TtsRenderKey::for($base, TtsOutputType::Mixed),
            TtsRenderKey::for($otherCaller, TtsOutputType::Mixed),
        );
    }

    /** Every digest stored before this existed still compares equal, so nothing went stale on deploy. */
    public function testAConversationsDigestIsUnchanged(): void
    {
        $utterances = $this->builder(RecordingType::Mixed)
            ->build($this->diarizedJob(), TtsOutputType::Mixed)
            ->utterances;

        // The shape the digest has always hashed: [role, text] pairs, with no third element.
        self::assertSame(
            hash('sha256', json_encode(
                ['v1', 'MIXED', [
                    ['CUSTOMER', 'Hi, can I get a large pepperoni?'],
                    ['AGENT', 'Pickup or delivery?'],
                    ['CUSTOMER', 'Pickup please.'],
                ]],
                JSON_THROW_ON_ERROR,
            )),
            TtsSourceDigest::for(TtsOutputType::Mixed, $utterances),
        );
    }

    // ------------------------------------------------------------------ voice configuration

    /** An unset side voice is reported for that side only, never as a confirmation problem. */
    public function testAnUnsetSideVoiceIsReportedOnItsOwn(): void
    {
        $tts = AudioToTextSettingsFactory::create(ttsApiKey: 'test-key', ttsCallerModel: '')->tts;

        self::assertSame(
            'The Caller voice is not configured on this server.',
            $tts->voiceProblem(TtsVoice::Caller),
        );
        self::assertNull($tts->voiceProblem(TtsVoice::Callee));
        // And the feature as a whole is still usable: a mixed recording is unaffected.
        self::assertTrue($tts->isUsable());
    }

    // ------------------------------------------------------------------ helpers

    /**
     * A completed COMMON recording the diarizer split into two clusters and guessed roles for.
     *
     * @param list<array<string, mixed>>|null $reviewed
     */
    private function diarizedJob(?array $reviewed = null): TranscriptionJob
    {
        $segments = [
            ['start_ms' => 0, 'end_ms' => 3000, 'speaker' => 'SPEAKER_00', 'role' => 'CUSTOMER',
                'text' => 'Hi, can I get a large pepperoni?', 'confidence' => 0.9, 'approx' => false],
            ['start_ms' => 3500, 'end_ms' => 5500, 'speaker' => 'SPEAKER_01', 'role' => 'AGENT',
                'text' => 'Pickup or delivery?', 'confidence' => 0.9, 'approx' => false],
            ['start_ms' => 6000, 'end_ms' => 7200, 'speaker' => 'SPEAKER_00', 'role' => 'CUSTOMER',
                'text' => 'Pickup please.', 'confidence' => 0.9, 'approx' => false],
        ];

        return TranscriptionJobFactory::mixedRecording(
            segments: $segments,
            reviewedSegments: $reviewed,
            transcript: 'Hi, can I get a large pepperoni? Pickup or delivery? Pickup please.',
            // NEEDS_REVIEW is the state that blocks a conversation, so it is the state worth using:
            // a side recording must be eligible from exactly the separation that stops a mixed one.
            separationStatus: SpeakerSeparationStatus::NEEDS_REVIEW,
        );
    }

    private function builder(?RecordingType $type): TtsScriptBuilder
    {
        return new TtsScriptBuilder(
            new EffectiveConversationReader(new SpeakerSegmentsDecoder()),
            new RecordingVoiceReader(FixedRecordingTypes::everything($type)),
        );
    }

    private function service(?RecordingType $type): TtsGenerationService
    {
        return new TtsGenerationService(
            new InMemoryTtsRenditionRepository(),
            $this->builder($type),
            AudioToTextSettingsFactory::create(ttsApiKey: 'test-key'),
        );
    }
}
