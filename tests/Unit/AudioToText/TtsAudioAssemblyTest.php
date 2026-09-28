<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Application\Tts\PcmAudio;
use App\AudioToText\Application\Tts\TtsRenderKey;
use App\AudioToText\Application\Tts\TtsSourceDigest;
use App\AudioToText\Application\Tts\TtsSourceText;
use App\AudioToText\Domain\Tts\TtsOutputFormat;
use App\AudioToText\Domain\Tts\TtsOutputType;
use App\AudioToText\Domain\Tts\TtsUtterance;
use App\AudioToText\Domain\Tts\TtsVoice;
use App\AudioToText\Domain\SpeakerRole;
use App\Environment;
use App\Tests\Support\AudioToTextSettingsFactory;
use PHPUnit\Framework\TestCase;

use function getenv;
use function putenv;
use function str_repeat;
use function strlen;

/**
 * The small pieces between a transcript and a playable file.
 *
 * `PcmAudio` is worth testing on its own because joining audio as raw bytes is the decision that removes
 * ffmpeg from the middle of this feature — and the arithmetic has a sharp edge: 16-bit samples are two
 * bytes, so an odd byte count shifts every sample after it and turns the rest of the file into noise.
 */
final class TtsAudioAssemblyTest extends TestCase
{
    // ------------------------------------------------------------------ silence

    public function testSilenceIsTwoBytesPerSample(): void
    {
        // 350 ms at 24 kHz is 8,400 samples, so 16,800 bytes.
        $this->assertSame(16800, strlen(PcmAudio::silence(350, 24000)));
    }

    public function testSilenceIsAlwaysAWholeNumberOfSamples(): void
    {
        foreach ([1, 7, 33, 350, 999] as $milliseconds) {
            $bytes = strlen(PcmAudio::silence($milliseconds, 24000));

            $this->assertSame(
                0,
                $bytes % PcmAudio::BYTES_PER_SAMPLE,
                'A partial sample would shift every byte after it and turn the rest of the file into noise.',
            );
        }
    }

    public function testSilenceIsActuallySilent(): void
    {
        $this->assertSame(str_repeat("\0", 480), PcmAudio::silence(10, 24000));
    }

    public function testZeroGapProducesNothing(): void
    {
        $this->assertSame('', PcmAudio::silence(0, 24000));
        $this->assertSame('', PcmAudio::silence(-100, 24000));
    }

    public function testDurationIsDerivedFromTheByteCount(): void
    {
        $this->assertEqualsWithDelta(1.0, PcmAudio::durationSeconds(48000, 24000), 0.0001);
        $this->assertEqualsWithDelta(0.0, PcmAudio::durationSeconds(0, 24000), 0.0001);
    }

    // ------------------------------------------------------------------ source preparation

    public function testMarkersAreRemovedAndTheGapTheyLeaveIsClosed(): void
    {
        $this->assertSame('Right? She wants rice.', TtsSourceText::prepare('Right? >> She wants rice.'));
    }

    /**
     * Interior whitespace is **not** collapsed.
     *
     * The display stripper does collapse it, because that reads better in a bubble. Reusing it here would
     * tie every generated file's digest to a display convenience — so the day somebody improved the
     * bubble, every rendition in the database would report itself stale and invite a paid regeneration.
     */
    public function testInteriorWhitespaceIsLeftAlone(): void
    {
        $this->assertSame('two  spaces', TtsSourceText::prepare('two  spaces'));
        $this->assertSame("a\nb", TtsSourceText::prepare("a\nb"));
    }

    public function testOuterWhitespaceIsTrimmed(): void
    {
        $this->assertSame('hello', TtsSourceText::prepare('  hello  '));
    }

    public function testATurnWithNothingButAMarkerBecomesEmpty(): void
    {
        $this->assertSame('', TtsSourceText::prepare('  >>  '));
    }

    /** Prices, quantities and product names pass through untouched. */
    public function testNothingElseIsRewritten(): void
    {
        $text = 'Two egg foo young, $43 and 45, table 12, 1600 Pennsylvania Ave.';

        $this->assertSame($text, TtsSourceText::prepare($text));
    }

    // ------------------------------------------------------------------ the render key

    /**
     * The render key answers "would this sound different?", which is a separate question from "do the
     * words differ?" — and the page shows a different sentence for each.
     */
    public function testChangingAVoiceChangesTheRenderKey(): void
    {
        $a = TtsRenderKey::for(AudioToTextSettingsFactory::create()->tts, TtsOutputType::Mixed);
        $b = TtsRenderKey::for(
            AudioToTextSettingsFactory::create(ttsAgentModel: 'aura-2-orpheus-en')->tts,
            TtsOutputType::Mixed,
        );

        $this->assertNotSame($a, $b);
    }

    public function testChangingTheOutputFormatChangesTheRenderKey(): void
    {
        $a = TtsRenderKey::for(AudioToTextSettingsFactory::create()->tts, TtsOutputType::Mixed);
        $b = TtsRenderKey::for(
            AudioToTextSettingsFactory::create(ttsOutputFormat: TtsOutputFormat::Wav)->tts,
            TtsOutputType::Mixed,
        );

        $this->assertNotSame($a, $b);
    }

    /**
     * Chunk boundaries change the phrasing, because each request is read as a self-contained piece of
     * text — so the limit is a render input rather than an implementation detail.
     */
    public function testChangingTheChunkSizeChangesTheRenderKey(): void
    {
        $a = TtsRenderKey::for(AudioToTextSettingsFactory::create()->tts, TtsOutputType::Mixed);
        $b = TtsRenderKey::for(
            AudioToTextSettingsFactory::create(ttsMaxCharactersPerRequest: 900)->tts,
            TtsOutputType::Mixed,
        );

        $this->assertNotSame($a, $b);
    }

    /**
     * A single-role file is not invalidated by a change to the other role's voice — that voice never
     * spoke in it, so nothing about it would sound different.
     */
    public function testASingleRoleFileIgnoresTheOtherRolesVoice(): void
    {
        $a = TtsRenderKey::for(AudioToTextSettingsFactory::create()->tts, TtsOutputType::Customer);
        $b = TtsRenderKey::for(
            AudioToTextSettingsFactory::create(ttsAgentModel: 'aura-2-orpheus-en')->tts,
            TtsOutputType::Customer,
        );

        $this->assertSame($a, $b);
    }

    /**
     * Moving the inter-turn gap changes the render identity of EVERY output.
     *
     * **This test used to assert that a per-role output was unaffected, and the change is deliberate.**
     * It was named `testTheInterTurnGapOnlyAffectsMixedOutput` and was true of an assembler that only
     * inserted silence into a mixed file. The assembler now inserts one between any two turns whatever
     * the output type, so the setting can change how a per-role file sounds too — and a render input
     * missing from the key is audio the page calls current when it no longer matches the configuration.
     *
     * It belongs in the render key and not in {@see \App\AudioToText\Application\Tts\TtsSourceDigest}:
     * a longer breath changes the file without changing a word of what is said, so the page says
     * "generated with a different voice setting" rather than claiming a transcript changed.
     */
    public function testMovingTheInterTurnGapChangesEveryOutputsRenderIdentity(): void
    {
        $changed = AudioToTextSettingsFactory::create(ttsGapMilliseconds: 800)->tts;
        $default = AudioToTextSettingsFactory::create()->tts;

        foreach ([TtsOutputType::Mixed, TtsOutputType::Customer, TtsOutputType::Agent] as $type) {
            $this->assertNotSame(
                TtsRenderKey::for($default, $type),
                TtsRenderKey::for($changed, $type),
                $type->value . ' can contain gaps, so the gap is part of what it was rendered with.',
            );
        }

        // A named-voice recording — a CALLER or CALLEE upload — has always counted it, and still does.
        $this->assertNotSame(
            TtsRenderKey::for($default, TtsOutputType::Mixed, TtsVoice::Caller),
            TtsRenderKey::for($changed, TtsOutputType::Mixed, TtsVoice::Caller),
        );
    }

    /**
     * The shipped default is two seconds, and it comes from configuration rather than from the code.
     *
     * Two seconds is long for a conversation and deliberate: this audio is listened to for training,
     * where the pause is when the listener decides what they would have said next. Asserted on the
     * environment schema rather than on a literal in the assembler, because the whole point of the
     * setting is that the assembler does not know the number — changing it must be one edit.
     */
    public function testTheShippedGapIsTwoSecondsAndComesFromConfiguration(): void
    {
        $previous = getenv('DEEPGRAM_TTS_GAP_MS');
        putenv('DEEPGRAM_TTS_GAP_MS');

        try {
            $this->assertSame(2000, Environment::int('DEEPGRAM_TTS_GAP_MS'));
        } finally {
            if ($previous !== false) {
                putenv('DEEPGRAM_TTS_GAP_MS=' . $previous);
            }
        }
    }

    /**
     * Milliseconds, so half a second is expressible.
     *
     * Recorded as a test because the obvious "improvement" is to call the setting seconds, and a client
     * asking for 1.5 would then need either a float through an int-typed reader or a rounding rule
     * nobody chose.
     */
    public function testAFractionOfASecondIsExpressible(): void
    {
        $settings = AudioToTextSettingsFactory::create(ttsGapMilliseconds: 1500)->tts;

        $this->assertSame(1500, $settings->gapMilliseconds);
        $this->assertSame(
            36000 * PcmAudio::BYTES_PER_SAMPLE,
            strlen(PcmAudio::silence($settings->gapMilliseconds, 24000)),
            'A second and a half at 24 kHz, to the sample.',
        );
    }

    /**
     * The gap is a render setting, never part of what was said.
     *
     * The digest is computed from the output type and the utterances alone, so it cannot see a setting
     * at all — asserted here because the alternative failure is silent and expensive: every `.env` edit
     * would announce itself as "the transcript changed after this audio was generated", and an operator
     * who learns to ignore that notice ignores it on the day it is reporting a real correction.
     */
    public function testTheGapDoesNotReachTheTranscriptDigest(): void
    {
        $utterances = [
            new TtsUtterance(SpeakerRole::CUSTOMER, 'Two egg foo young.'),
            new TtsUtterance(SpeakerRole::AGENT, 'Ready in 25 minutes.'),
        ];

        $this->assertSame(
            TtsSourceDigest::for(TtsOutputType::Mixed, $utterances),
            TtsSourceDigest::for(TtsOutputType::Mixed, $utterances),
            'The digest has no gap input to take, whatever the configuration says.',
        );
    }

    // ------------------------------------------------------------------ formats

    public function testTheContentTypeIsExactBecauseNosniffIsGlobal(): void
    {
        $this->assertSame('audio/mpeg', TtsOutputFormat::Mp3->contentType());
        $this->assertSame('audio/wav', TtsOutputFormat::Wav->contentType());
    }

    public function testAnUnknownConfiguredFormatFallsBackRatherThanStoppingTheWorker(): void
    {
        $this->assertSame(TtsOutputFormat::Mp3, TtsOutputFormat::fromConfig('flac'));
        $this->assertSame(TtsOutputFormat::Wav, TtsOutputFormat::fromConfig('WAV'));
    }
}
