<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Application\EffectiveConversationReader;
use App\AudioToText\Application\Speaker\SpeakerSegmentsDecoder;
use App\AudioToText\Application\Tts\TtsScriptBuilder;
use App\AudioToText\Domain\SourceRole;
use App\AudioToText\Domain\SpeakerRole;
use App\AudioToText\Domain\Tts\TtsOutputType;
use App\Tests\Support\TranscriptionJobFactory;
use PHPUnit\Framework\TestCase;

use function array_map;

/**
 * What the audio will actually say.
 *
 * Two properties carry the whole feature, and everything else here is in service of them:
 *
 * 1. **The corrected transcript wins.** An administrator who fixes "one ton" to "wonton" must hear
 *    "wonton". If this read the machine column instead, the feature would confidently reproduce the
 *    mistake it exists to fix.
 * 2. **Nothing else is changed.** No summarising, no re-pricing, no display formatting. The only
 *    transformation is the documented one in `TtsSourceText`, and the tests below check what it does
 *    *and* what it leaves alone.
 */
final class TtsScriptBuilderTest extends TestCase
{
    private TtsScriptBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new TtsScriptBuilder(new EffectiveConversationReader(new SpeakerSegmentsDecoder()));
    }

    // ------------------------------------------------------- the corrected transcript wins

    /**
     * The case this whole feature exists for.
     *
     * Speech recognition heard "one ton"; an administrator corrected it to "wonton". The generated audio
     * has to say wonton, because a trainee listening to it is being taught what the customer ordered.
     */
    public function testACorrectionIsWhatGetsSpoken(): void
    {
        $job = TranscriptionJobFactory::mixedRecording(
            segments: [
                ['role' => 'CUSTOMER', 'speaker' => 'SPEAKER_00', 'text' => 'One ton soup please.'],
            ],
            agentText: 'Ready in 25 minutes.',
            customerText: 'One ton soup please.',
            reviewedSegments: [
                ['role' => 'CUSTOMER', 'speaker' => 'SPEAKER_00', 'text' => 'Wonton soup please.'],
            ],
            reviewedAgentText: 'Ready in 25 minutes.',
            reviewedCustomerText: 'Wonton soup please.',
        );

        $script = $this->builder->build($job, TtsOutputType::Mixed);

        $this->assertSame(['Wonton soup please.'], $this->texts($script->utterances));
    }

    public function testWithNoCorrectionTheMachineTranscriptIsSpoken(): void
    {
        $job = TranscriptionJobFactory::mixedRecording(
            segments: [['role' => 'CUSTOMER', 'speaker' => 'SPEAKER_00', 'text' => 'One ton soup please.']],
            customerText: 'One ton soup please.',
            agentText: 'Ready in 25 minutes.',
        );

        $this->assertSame(
            ['One ton soup please.'],
            $this->texts($this->builder->build($job, TtsOutputType::Mixed)->utterances),
        );
    }

    // ------------------------------------------------------------------ verbatim

    /**
     * Every kind of content the instructions name as untouchable, in one turn.
     *
     * Asserted character for character. A formatter quietly applied here — `SpokenPrice` is the obvious
     * candidate, since it is applied on several *display* surfaces — would change a price in audio that
     * claims to be a reading of the transcript.
     */
    public function testProductNamesQuantitiesPricesAndAddressesAreSpokenExactly(): void
    {
        $text = 'Two egg foo young and 3 wonton soup, $43 and 45, to 1600 Pennsylvania Ave, card ending 4242.';

        $job = TranscriptionJobFactory::mixedRecording(
            segments: [['role' => 'CUSTOMER', 'speaker' => 'SPEAKER_00', 'text' => $text]],
            customerText: $text,
            agentText: 'Ok.',
        );

        $this->assertSame(
            [$text],
            $this->texts($this->builder->build($job, TtsOutputType::Mixed)->utterances),
            'The spoken text must be byte-identical to the transcript.',
        );
    }

    /** `>>` is a display marker, not a word. Left in, Deepgram reads it aloud. */
    public function testSpeakerChangeMarkersAreNotReadAloud(): void
    {
        $job = TranscriptionJobFactory::mixedRecording(
            segments: [['role' => 'AGENT', 'speaker' => 'SPEAKER_00', 'text' => 'Right? >> She wants rice.']],
            agentText: 'Right? She wants rice.',
            customerText: 'Ok.',
        );

        $this->assertSame(
            ['Right? She wants rice.'],
            $this->texts($this->builder->build($job, TtsOutputType::Mixed)->utterances),
        );
    }

    // ------------------------------------------------------------------ order and roles

    public function testTurnsKeepTheirChronologicalOrderAndSpeakers(): void
    {
        $job = TranscriptionJobFactory::mixedRecording(
            segments: [
                ['role' => 'CUSTOMER', 'speaker' => 'SPEAKER_00', 'text' => 'Hi, can I order?'],
                ['role' => 'AGENT', 'speaker' => 'SPEAKER_01', 'text' => 'Go ahead.'],
                ['role' => 'CUSTOMER', 'speaker' => 'SPEAKER_00', 'text' => 'Two egg foo young.'],
            ],
            agentText: 'Go ahead.',
            customerText: 'Hi, can I order? Two egg foo young.',
        );

        $script = $this->builder->build($job, TtsOutputType::Mixed);

        $this->assertSame(
            ['Hi, can I order?', 'Go ahead.', 'Two egg foo young.'],
            $this->texts($script->utterances),
        );
        $this->assertSame(
            [SpeakerRole::CUSTOMER, SpeakerRole::AGENT, SpeakerRole::CUSTOMER],
            array_map(static fn($u) => $u->role, $script->utterances),
        );
    }

    /**
     * A third voice, or speech nobody could attribute, has no honest voice to be read in.
     *
     * Counted rather than dropped in silence: the page reports the number, so an administrator can go
     * and reassign them if they belong in the call.
     */
    public function testUnattributableTurnsAreOmittedAndCounted(): void
    {
        $job = TranscriptionJobFactory::mixedRecording(
            segments: [
                ['role' => 'CUSTOMER', 'speaker' => 'SPEAKER_00', 'text' => 'Two egg foo young.'],
                ['role' => 'OTHER', 'speaker' => 'SPEAKER_02', 'text' => 'Television in the background.'],
                ['role' => 'UNKNOWN', 'speaker' => 'UNKNOWN', 'text' => 'Muffled.'],
                ['role' => 'AGENT', 'speaker' => 'SPEAKER_01', 'text' => 'Ready in 25 minutes.'],
            ],
            agentText: 'Ready in 25 minutes.',
            customerText: 'Two egg foo young.',
        );

        $script = $this->builder->build($job, TtsOutputType::Mixed);

        $this->assertSame(['Two egg foo young.', 'Ready in 25 minutes.'], $this->texts($script->utterances));
        $this->assertSame(2, $script->omittedTurns);
    }

    /** A turn that was only a marker is nothing, not omitted content — so it is not reported as loss. */
    public function testATurnWithNothingLeftInItIsNotCountedAsOmitted(): void
    {
        $job = TranscriptionJobFactory::mixedRecording(
            segments: [
                ['role' => 'CUSTOMER', 'speaker' => 'SPEAKER_00', 'text' => '>>'],
                ['role' => 'AGENT', 'speaker' => 'SPEAKER_01', 'text' => 'Ready in 25 minutes.'],
            ],
            agentText: 'Ready in 25 minutes.',
            customerText: '',
        );

        $script = $this->builder->build($job, TtsOutputType::Mixed);

        $this->assertSame(['Ready in 25 minutes.'], $this->texts($script->utterances));
        $this->assertSame(0, $script->omittedTurns);
    }

    // ------------------------------------------------------------------ separate uploads

    /**
     * A separate child was never diarized, so it has no turns at all — its whole transcript is one side.
     */
    public function testASeparateCustomerRecordingBecomesOneCustomerUtterance(): void
    {
        $job = TranscriptionJobFactory::separateRecording(SourceRole::Customer, 'Two egg foo young, no MSG.');

        $script = $this->builder->build($job, TtsOutputType::Customer);

        $this->assertSame(['Two egg foo young, no MSG.'], $this->texts($script->utterances));
        $this->assertSame(SpeakerRole::CUSTOMER, $script->utterances[0]->role);
    }

    public function testASeparateAgentRecordingBecomesOneAgentUtterance(): void
    {
        $job = TranscriptionJobFactory::separateRecording(SourceRole::Agent, 'Ready in 25 minutes.');

        $script = $this->builder->build($job, TtsOutputType::Agent);

        $this->assertSame(SpeakerRole::AGENT, $script->utterances[0]->role);
        $this->assertSame(['Ready in 25 minutes.'], $this->texts($script->utterances));
    }

    public function testASideThatSaidNothingProducesAnEmptyScript(): void
    {
        $job = TranscriptionJobFactory::separateRecording(SourceRole::Customer, '');

        $this->assertTrue($this->builder->build($job, TtsOutputType::Customer)->isEmpty());
    }

    // ------------------------------------------------------------------ the billed quantity

    public function testTheCharacterCountIsTheSumOfWhatWillBeSpoken(): void
    {
        $job = TranscriptionJobFactory::mixedRecording(
            segments: [
                ['role' => 'CUSTOMER', 'speaker' => 'SPEAKER_00', 'text' => '12345'],
                ['role' => 'AGENT', 'speaker' => 'SPEAKER_01', 'text' => '123'],
            ],
            agentText: '123',
            customerText: '12345',
        );

        $this->assertSame(8, $this->builder->build($job, TtsOutputType::Mixed)->characterCount());
    }

    /**
     * @param list<\App\AudioToText\Domain\Tts\TtsUtterance> $utterances
     *
     * @return list<string>
     */
    private function texts(array $utterances): array
    {
        return array_map(static fn($u): string => $u->text, $utterances);
    }
}
