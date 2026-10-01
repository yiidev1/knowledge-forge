<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function strpos;
use function substr;

/**
 * Asking before transcribing.
 *
 * Pressing **Transcribe audio** used to start the work on the press. That is right for something cheap
 * and reversible, and transcription is neither: it spends CPU or money, it cannot be called back once a
 * provider has the audio, and the button sits in a row of three identical ones — mixed, caller, callee —
 * where the wrong click is easy and silent.
 *
 * So the press opens a confirmation and the work starts on a second, deliberate press. The property that
 * makes that worth anything is the one most easily lost in a later edit:
 *
 * **Opening the dialog must send nothing.** If the request ever moves back into the opener, the dialog
 * becomes a thing that reports what already happened rather than a thing that asks — which is worse than
 * no dialog at all, because it reads as a chance to say no.
 *
 * These read the shipped script and template rather than a browser, in the manner of
 * {@see ManualProcessingPanelTest}: the behaviour lives in two files and this is what pins it there.
 */
final class TranscribeConfirmationTest extends TestCase
{
    // ---------------------------------------------------------------- opening sends nothing

    /**
     * 1 & 2. The click opens the dialog, and the opener contains no request at all.
     *
     * Asserted as the absence of `fetch` in that function rather than by counting requests, because
     * that is the shape the rule has: there is no code path through opening that could send one.
     */
    public function testOpeningTheConfirmationSendsNothing(): void
    {
        $open = self::functionBody('function confirmTranscript(button) {');

        self::assertStringContainsString('openDialog(dialog);', $open);
        self::assertStringNotContainsString(
            'fetch(',
            $open,
            'Opening the confirmation must be free of side effects — no request, no status change.',
        );
        self::assertStringNotContainsString('method: \'POST\'', $open);

        // And the click handler opens it rather than starting anything.
        self::assertStringContainsString('confirmTranscript(transcribe);', self::script());
        self::assertStringNotContainsString('askForTranscript(', self::script());
    }

    /** 3. Cancel is the dialog's own close control, which by construction sends nothing. */
    public function testCancelOnlyCloses(): void
    {
        $template = self::storeTemplate();

        self::assertStringContainsString(
            '<button class="btn btn--secondary" type="button" data-a2t-dialog-close>Cancel</button>',
            $template,
            'Cancel must be an ordinary dialog close, not a control with behaviour of its own.',
        );

        // The close path in the script closes and nothing else — no request lives in it.
        $script = self::script();
        $closer = substr($script, (int) strpos($script, "var closer = target.closest('[data-a2t-dialog-close]');"), 400);
        self::assertStringNotContainsString('fetch(', $closer);
    }

    // ---------------------------------------------------------------- starting is deliberate

    /** 4. The request is sent by the confirm button, and by nothing else. */
    public function testOnlyTheConfirmButtonSendsTheRequest(): void
    {
        $start = self::functionBody('function startTranscript() {');

        self::assertStringContainsString("method: 'POST'", $start);
        self::assertStringContainsString("'X-CSRF-Token': reviewToken.value", $start);
        self::assertStringContainsString('startTranscript();', self::script());
    }

    /**
     * 5. A double click cannot produce two requests.
     *
     * The guard is set **before** the request rather than in its answer: the second click of a double
     * click arrives long before any response does, so a guard applied on completion would be applied
     * after the damage.
     */
    public function testTheConfirmButtonIsDisabledBeforeTheRequestIsSent(): void
    {
        $start = self::functionBody('function startTranscript() {');

        $guard = (int) strpos($start, 'confirm.disabled = true;');
        $request = (int) strpos($start, 'fetch(');

        self::assertGreaterThan(0, $guard, 'The double-submit guard has gone.');
        self::assertLessThan(
            $request,
            $guard,
            'The guard must be in place before the request, not after its answer.',
        );

        // And an already-disabled button is refused outright, so a queued second click does nothing.
        self::assertStringContainsString('if (!confirm || confirm.disabled) {', $start);
        self::assertStringContainsString("confirm.textContent = 'Starting…';", $start);
    }

    // ---------------------------------------------------------------- it is about ONE recording

    /**
     * 6 & 7. The dialog describes the recording whose button was pressed, and starts only that one.
     *
     * The url is read off that button and kept, so what is eventually sent cannot drift to another row:
     * there is no lookup at send time that could resolve to a different recording.
     */
    public function testTheConfirmationIsBoundToTheRecordingThatWasClicked(): void
    {
        $open = self::functionBody('function confirmTranscript(button) {');

        self::assertStringContainsString("button.getAttribute('data-a2t-transcribe')", $open);
        self::assertStringContainsString('pendingTranscribe = {', $open);

        $start = self::functionBody('function startTranscript() {');
        self::assertStringContainsString('var asked = pendingTranscribe;', $start);
        self::assertStringContainsString('fetch(asked.url, {', $start);

        // Each button carries its own context, so three buttons in one row describe three recordings.
        $template = self::storeTemplate();

        foreach ([
            'data-a2t-transcribe="',
            'data-a2t-transcribe-order="',
            'data-a2t-transcribe-duration="',
            'data-a2t-transcribe-provider="',
            'data-a2t-details-label="',
        ] as $attribute) {
            self::assertStringContainsString(
                $attribute,
                $template,
                'The confirmation reads its facts from the button, so the button must carry them.',
            );
        }
    }

    /** The provider named is the one the server will actually use, not the upload form's choice. */
    public function testTheProviderShownIsTheStoredDefault(): void
    {
        self::assertStringContainsString(
            "data-a2t-transcribe-provider=\"' . Html::encode(\$globalDefault->shortLabel())",
            self::storeTemplate(),
            'Naming the upload form\'s preselection would promise a provider the request will not use.',
        );
    }

    // ---------------------------------------------------------------- a refusal changes nothing

    /**
     * 8. A failed request leaves the recording ready, and says so plainly.
     *
     * The dialog deliberately stays open: nothing was asked for, so there is something to press again,
     * and closing it would leave the reader with no account of what happened.
     */
    public function testAFailedRequestDoesNotClaimTheTranscriptWasRequested(): void
    {
        $start = self::functionBody('function startTranscript() {');

        self::assertStringContainsString("refuse('Unable to start transcription. Please try again.');", $start);
        self::assertStringContainsString('confirm.textContent = \'Start transcription\';', $start);

        // The failure path must not open the progress view, which is what claims a transcript is coming.
        $catch = substr($start, (int) strpos($start, '.catch(function ()'));
        self::assertStringNotContainsString('showProgressOn(', $catch);
    }

    /** A refusal from the server is not reported as success either. */
    public function testARefusedRequestRereadsThePageRatherThanClaimingSuccess(): void
    {
        $start = self::functionBody('function startTranscript() {');

        self::assertStringContainsString('if (!data.success || !data.statusUrl) {', $start);
        self::assertStringContainsString('window.location.reload();', $start);
    }

    // ---------------------------------------------------------------- the shell it is built from

    /** It is a dialog like the others: accessible title, Escape, backdrop, no inline script. */
    public function testTheConfirmationUsesTheApplicationsOwnDialogShell(): void
    {
        $template = self::storeTemplate();

        self::assertStringContainsString('id="a2t-transcribe-dialog" data-a2t-dialog', $template);
        self::assertStringContainsString('aria-labelledby="a2t-transcribe-title"', $template);
        self::assertStringContainsString('<h2 class="source-modal__title" id="a2t-transcribe-title">', $template);

        // `showModal()` is what gives Escape, the backdrop and the inert background — none of which is
        // reimplemented here.
        self::assertStringContainsString('dialog.showModal();', self::script());

        // The policy is `script-src 'self'`: an inline handler would be dropped without warning.
        self::assertStringNotContainsString('onclick=', $template);
        self::assertStringNotContainsString('<script', $template);
    }

    /** Focus returns to the button that opened it, however the dialog was closed. */
    public function testFocusReturnsToTheButtonThatOpenedIt(): void
    {
        $script = self::script();

        self::assertStringContainsString('if (pendingTranscribe && pendingTranscribe.button) {', $script);
        self::assertStringContainsString('opener.focus();', $script);
        // On the dialog's own close event, so Escape and a backdrop click are covered as well as Cancel.
        self::assertStringContainsString("dialog.addEventListener('close', function () {", $script);
    }

    /** 10. And none of it says queue, worker or job. */
    public function testTheConfirmationSaysNothingAboutTheMachinery(): void
    {
        $dialog = self::dialogMarkup();

        foreach (['queue', 'queued', 'worker', 'job', 'NOT_REQUESTED', 'QUEUED'] as $banned) {
            self::assertStringNotContainsStringIgnoringCase(
                $banned,
                $dialog,
                'The confirmation names this application\'s internals rather than the reader\'s audio.',
            );
        }

        self::assertStringContainsString('Transcribe this audio?', $dialog);
        self::assertStringContainsString('Start transcription', $dialog);
    }

    // ---------------------------------------------------------------- helpers

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function script(): string
    {
        return (string) file_get_contents(self::root() . '/assets/audio-store/audio-store.js');
    }

    private static function storeTemplate(): string
    {
        return (string) file_get_contents(self::root() . '/src/AudioToText/Web/Job/Store/template.php');
    }

    /** Just the confirmation dialog, so the vocabulary check is not reading the rest of the page. */
    private static function dialogMarkup(): string
    {
        $template = self::storeTemplate();
        $start = strpos($template, '<dialog class="source-modal a2t-confirm-dialog"');
        self::assertNotFalse($start, 'The confirmation dialog has moved.');

        $end = strpos($template, '</dialog>', $start);
        self::assertNotFalse($end);

        return substr($template, $start, $end - $start);
    }

    private static function functionBody(string $signature): string
    {
        $script = self::script();
        $start = strpos($script, $signature);
        self::assertNotFalse($start, $signature . ' has moved.');

        $end = strpos($script, "\n    }\n", $start);
        self::assertNotFalse($end);

        return substr($script, $start, $end - $start);
    }
}
