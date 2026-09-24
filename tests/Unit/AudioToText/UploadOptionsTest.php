<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Application\UploadOptions;
use App\AudioToText\Domain\TranscriptionProvider;
use App\Tests\Support\AudioToTextSettingsFactory;
use Codeception\Test\Unit;

use function dirname;
use function file_get_contents;
use function preg_match_all;

/**
 * The two choices every upload carries, and the one object that decides what they mean.
 *
 * Both are posted by a browser and both decide something expensive: which engine runs, and whether
 * clean audio is bought. There are now two forms that post them — the store page's upload dialog and
 * the Manage Audio replacement — and the failure this suite exists to prevent is not a crash. It is
 * the two quietly disagreeing: a replacement accepting a provider the upload form would have refused,
 * or reading a missing checkbox as anything other than "no".
 */
final class UploadOptionsTest extends Unit
{
    /** Whisper is configured in these settings; Deepgram has no key, so it cannot run. */
    private function options(string $deepgramKey = ''): UploadOptions
    {
        return new UploadOptions(AudioToTextSettingsFactory::create(
            // Point the binaries at something that exists, so "usable" is about configuration rather
            // than about this machine's /opt layout.
            ffmpegBinary: '/bin/sh',
            ffprobeBinary: '/bin/sh',
            whisperBinary: '/bin/sh',
            whisperModel: __FILE__,
            deepgramApiKey: $deepgramKey,
        ));
    }

    // ------------------------------------------------------------------------------- the provider

    /** Nothing posted is not an error: the default stands. */
    public function testAnAbsentProviderFieldKeepsTheDefault(): void
    {
        [$provider, $problem] = $this->options()->provider([], TranscriptionProvider::Whisper);

        self::assertSame(TranscriptionProvider::Whisper, $provider);
        self::assertNull($problem);
    }

    /** An empty string is the same as absent — a select that was rendered but never chosen from. */
    public function testAnEmptyProviderFieldKeepsTheDefault(): void
    {
        [$provider, $problem] = $this->options()
            ->provider(['transcription_provider' => ''], TranscriptionProvider::Whisper);

        self::assertSame(TranscriptionProvider::Whisper, $provider);
        self::assertNull($problem);
    }

    /**
     * A value this application does not issue is refused rather than defaulted.
     *
     * Silently accepting Whisper for a request that asked for something else would report success for
     * a choice nobody made, and the transcript would be produced by an engine the administrator did
     * not pick.
     */
    public function testAProviderThatIsNotOneOfOursIsRefused(): void
    {
        [$provider, $problem] = $this->options()
            ->provider(['transcription_provider' => 'ACME'], TranscriptionProvider::Whisper);

        self::assertSame(TranscriptionProvider::Whisper, $provider, 'Nothing is adopted from a bad value.');
        self::assertNotNull($problem);
        self::assertStringContainsString('listed transcription providers', $problem);
    }

    /** A real provider this server cannot run is refused before anything is stored or queued. */
    public function testAProviderThisServerCannotRunIsRefusedWithItsReason(): void
    {
        [, $problem] = $this->options()
            ->provider(['transcription_provider' => 'DEEPGRAM'], TranscriptionProvider::Whisper);

        self::assertNotNull($problem);
        self::assertStringContainsString('Deepgram (cloud)', $problem);
        self::assertStringContainsString('not configured on this server', $problem);
    }

    /** Configured, it is accepted — the same rule, the other way round. */
    public function testAConfiguredProviderIsAccepted(): void
    {
        [$provider, $problem] = $this->options('dg-key')
            ->provider(['transcription_provider' => 'DEEPGRAM'], TranscriptionProvider::Whisper);

        self::assertSame(TranscriptionProvider::Deepgram, $provider);
        self::assertNull($problem);
    }

    /** A body that is not an array at all — no form, or one this server could not parse. */
    public function testAnUnparseableBodyKeepsTheDefault(): void
    {
        [$provider, $problem] = $this->options()->provider(null, TranscriptionProvider::Whisper);

        self::assertSame(TranscriptionProvider::Whisper, $provider);
        self::assertNull($problem);
    }

    // ------------------------------------------------------------------------- what a field starts on

    /**
     * A field never starts on a provider this machine cannot run.
     *
     * A select whose only `selected` option is `disabled` still submits that option, so preselecting
     * an unusable provider would produce a form that fails when submitted unchanged.
     */
    public function testAnUnusablePreferenceFallsBackToOneThatCanRun(): void
    {
        self::assertSame(
            TranscriptionProvider::Whisper,
            $this->options()->preselected(TranscriptionProvider::Deepgram),
        );
    }

    /** A usable preference is kept — a replacement starts on the engine it is replacing. */
    public function testAUsablePreferenceIsKept(): void
    {
        self::assertSame(
            TranscriptionProvider::Deepgram,
            $this->options('dg-key')->preselected(TranscriptionProvider::Deepgram),
        );
    }

    /** Every provider is reported, whether or not it can run: a hidden choice explains nothing. */
    public function testEveryProviderIsReportedWithItsAvailability(): void
    {
        $usable = $this->options()->usability();

        self::assertSame(['WHISPER' => true, 'DEEPGRAM' => false], $usable);
        self::assertTrue($this->options('dg-key')->usability()['DEEPGRAM']);
    }

    public function testCanRunAnswersForOneNamedProvider(): void
    {
        self::assertFalse($this->options()->canRun(TranscriptionProvider::Deepgram));
        self::assertTrue($this->options()->canRun(TranscriptionProvider::Whisper));
    }

    // ------------------------------------------------------------------------------- the paid box

    /**
     * Absence is the "no", and that is the whole reason "off" is a reliable default.
     *
     * An unticked checkbox posts nothing at all. If absence were read as anything but false, every
     * form would have to remember to say "off" — and the one that forgot would spend money.
     */
    public function testAnAbsentCheckboxIsNo(): void
    {
        self::assertFalse($this->options()->wantsAiAudio([]));
        self::assertFalse($this->options()->wantsAiAudio(null));
        self::assertFalse($this->options()->wantsAiAudio(['generate_ai_audio' => null]));
    }

    /** Present is the "yes": a browser posts the field only because the box was ticked. */
    public function testAPresentCheckboxIsYes(): void
    {
        self::assertTrue($this->options()->wantsAiAudio(['generate_ai_audio' => '1']));
        self::assertTrue($this->options()->wantsAiAudio(['generate_ai_audio' => 'on']));
    }

    public function testPaidAudioReportsWhetherThisServerCanGenerateAtAll(): void
    {
        self::assertFalse($this->options()->aiAudioIsUsable(), 'No TTS key in these settings.');
    }

    // --------------------------------------------------------------------------- one authority only

    /**
     * Neither action re-decides either rule for itself.
     *
     * This is the assertion the class exists for. `providerIsUsable` and `ttsIsUsable` are the two
     * settings calls that answer "may this run" and "may this be paid for"; the moment either action
     * calls one directly it has an opinion of its own, and the two forms can disagree about a
     * provider or about money without anything failing.
     */
    public function testNeitherUploadActionDecidesTheseRulesItself(): void
    {
        $root = dirname(__DIR__, 3) . '/src/AudioToText/Web/Job/Store/';

        foreach (['Action.php', 'Group/ReplaceAction.php', 'Group/RecordingsAction.php'] as $file) {
            $source = (string) file_get_contents($root . $file);

            self::assertSame(
                0,
                preg_match_all('/providerIsUsable\(|ttsIsUsable\(/', $source),
                $file . ' must ask UploadOptions rather than deciding availability itself.',
            );
            self::assertSame(
                0,
                preg_match_all('/generate_ai_audio.{0,40}\?\?/', $source),
                $file . ' must not parse the paid checkbox itself.',
            );
        }
    }
}
