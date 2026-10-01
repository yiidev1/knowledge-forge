<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function preg_match;
use function preg_match_all;
use function strpos;
use function substr;

/**
 * One progress card, one mapping, three places it appears.
 *
 * Three surfaces wait on the same worker: the Update Audio dialog, the AI-audio confirmation, and the
 * Details dialog opened on a recording that is still being transcribed. They were three different
 * answers to one question — the third used to say the single word "Processing", because a job without a
 * transcript has no Details to open at all.
 *
 * What these tests protect is the *singleness*: one partial, one view model, one renderer, one bar. A
 * second copy of any of them would be correct on the day it was written and wrong on the day either was
 * touched, and nothing in a screenshot would show which.
 *
 * @see \App\AudioToText\Web\AudioToTextViews::processingCard() the one file all three render
 */
final class ManualProcessingPanelTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function script(): string
    {
        return (string) file_get_contents(self::root() . '/assets/audio-store/audio-store.js');
    }

    private static function card(): string
    {
        return (string) file_get_contents(self::root() . '/src/AudioToText/Web/_partial/processing-card.php');
    }

    private static function storeTemplate(): string
    {
        return (string) file_get_contents(self::root() . '/src/AudioToText/Web/Job/Store/template.php');
    }

    private static function css(): string
    {
        return (string) file_get_contents(self::root() . '/assets/audio-store/audio-store.css');
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

    // ---------------------------------------------------------------- one card, one bar

    // ---------------------------------------------------------------- one component

    /**
     * 1 & 13. Five surfaces, one file. The upload card renders it too.
     *
     * That last part is the point of this round: "Recording progress" was inline markup that four other
     * places imitated. Now it is the component, and the imitations are gone — a change to a step's shape
     * reaches the upload form and every dialog together, because there is only one shape.
     */
    public function testEverySurfaceRendersTheOneSharedCard(): void
    {
        $template = self::storeTemplate();

        self::assertSame(
            4,
            preg_match_all('/AudioToTextViews::processingCard\(\)/', $template),
            'Upload, Update, AI audio and Details — one partial, four renders.',
        );

        self::assertStringContainsString("'title' => 'Recording progress',", $template);
        self::assertStringContainsString("'title' => 'Update Audio Progress',", $template);
        self::assertStringContainsString("'title' => 'AI Audio Progress',", $template);
        self::assertStringContainsString("'title' => 'Transcription Progress',", $template);

        // The markup exists once, in the partial, and nowhere else.
        self::assertSame(0, preg_match('/a2t-upload-feedback__heading/', $template));
        self::assertSame(1, preg_match_all('/a2t-upload-feedback__heading/', self::card()));
    }

    /**
     * The upload card keeps its own attribute hooks, so its script is untouched.
     *
     * Sharing the structure is worth doing; rewriting a working upload flow to share the wiring as well
     * is not, and would put 158 store tests at risk for no gain a reader could see.
     */
    public function testTheUploadCardKeepsItsOwnHooks(): void
    {
        $template = self::storeTemplate();

        foreach ([
            'data-a2t-feedback',
            'data-a2t-state-label',
            'data-a2t-upload-percent',
            'data-a2t-upload-progress',
            'data-a2t-upload-status',
            'data-a2t-conversion-percent',
            'data-a2t-conversion-progress',
            'data-a2t-conversion-status',
            'data-a2t-upload-error',
            'data-a2t-upload-result',
        ] as $hook) {
            self::assertStringContainsString($hook, $template, $hook . ' was the upload script\'s and must stay.');
        }
    }

    /** Each card draws one numbered row per real stage — five, five, two, five. */
    public function testEachSurfaceDrawsOnlyTheStagesItActuallyHas(): void
    {
        $template = self::storeTemplate();

        // The transcription list is built once and reused by the two surfaces that need it.
        self::assertSame(1, preg_match_all('/\$transcriptionSteps = static fn/', $template));
        self::assertSame(2, preg_match_all("/'steps' => \\\$transcriptionSteps\\(\\),/", $template));

        // AI audio: two, and neither is a transcription stage.
        self::assertStringContainsString("'01', 'ACCEPTED', 'Request'", $template);
        self::assertStringContainsString("'02', 'GENERATING', 'Generate audio'", $template);

        $tts = substr($template, (int) strpos($template, "'title' => 'AI Audio Progress',"));
        $tts = substr($tts, 0, (int) strpos($tts, "]) ?>"));
        self::assertSame(0, preg_match('/TRANSCRIBING|DIARIZING|MAPPING_SPEAKERS|SAVING/', $tts));
    }

    // ---------------------------------------------------------------- step states

    /**
     * 2, 3, 4 & 5. Done is 100%, running is "Processing" with no figure, waiting is "Waiting".
     *
     * The only percentage anywhere is the one that is true. A running step's bar carries **no** value,
     * which makes it `:indeterminate` — the travelling segment the upload card has always used, already
     * stilled under prefers-reduced-motion.
     */
    public function testEachStepSaysExactlyWhatIsKnownAboutIt(): void
    {
        $body = self::functionBody('function renderProcessing(');

        // The checklist: done, running, waiting, failed — one walk, both layouts.
        self::assertStringContainsString(
            "state = index < reached ? 'complete' : (index === reached ? 'active' : 'pending');",
            $body,
        );
        self::assertStringContainsString(
            "state = index < reached ? 'complete' : (index === reached ? 'error' : 'pending');",
            $body,
            'A failure leaves the stages before it complete; they did happen.',
        );

        // The per-step layout's own bar and value, given only where the markup drew them.
        self::assertStringContainsString("stepBar.removeAttribute('value');", $body, 'Running: no figure.');
        self::assertStringContainsString("stepBar.value = state === 'complete' ? 100 : 0;", $body);
        self::assertStringContainsString("? '100%'", $body);
        self::assertStringContainsString("'Waiting'", $body);
    }

    /**
     * The figure is the workflow's position, stated once, and nothing but a new stage moves it.
     *
     * It reads as a measurement and it is one — of how far through five stages the job is, not of time.
     * What the tests below guard is that it can only come from the stage table: no arithmetic derives
     * it, and no timer touches it.
     */
    public function testTheFigureComesOnlyFromTheStageTable(): void
    {
        $script = self::script();
        $mapping = substr($script, (int) strpos($script, 'var PROCESSING_STAGES = {'));
        $mapping = substr($mapping, 0, (int) strpos($mapping, '};'));

        foreach ([10, 15, 20, 40, 65, 80, 95] as $position) {
            self::assertSame(
                1,
                preg_match('/percent: ' . $position . ',/', $mapping),
                'The table is the only place a figure is written down.',
            );
        }

        $render = self::functionBody('function renderProcessing(');

        self::assertStringContainsString('bar.value = model.percent;', $render);
        self::assertSame(
            0,
            preg_match('/percent\s*[+\-*\/]=|Math\.(min|max|round|floor)\([^)]*percent/', $render),
            'Nothing computes it; it is looked up.',
        );
    }

    /** 3. No timer anywhere drives a bar; the only interval counts elapsed seconds. */
    public function testNoTimerDrivesTheBar(): void
    {
        self::assertSame(
            1,
            preg_match_all('/window\.setInterval\(/', self::script()),
            'One interval in the whole file, and it is the elapsed clock.',
        );

        $ticker = self::functionBody('function elapsedTicker(');
        self::assertStringContainsString('elapsedWords', $ticker);
        self::assertStringNotContainsString('value', $ticker, 'The clock never touches a bar.');
    }

    /**
     * An indeterminate bar animates, and reduced motion stills it — both from the shared upload styles.
     *
     * That is AI audio's bar: a rendition reports no position in a workflow, so its overall bar carries
     * no value and travels instead of filling. The transcription bar is determinate and does not need
     * an animation to be truthful — its figure and its highlighted row say where it is.
     */
    public function testTheIndeterminateBarAnimatesFromTheSharedStyles(): void
    {
        $css = self::css();

        self::assertStringContainsString('.a2t-upload-progress:indeterminate {', $css);
        self::assertStringContainsString('animation: a2t-upload-progress-travel', $css);

        $reduced = substr($css, (int) strpos($css, '@media (prefers-reduced-motion: reduce)'), 400);
        self::assertStringContainsString('.a2t-upload-progress:indeterminate { animation: none;', $reduced);

        // AI audio passes no figure, which is what leaves its bar indeterminate.
        $tts = self::functionBody('function showTtsProcessing(');
        self::assertSame(0, preg_match('/percent:/', $tts));
        self::assertStringContainsString("bar.removeAttribute('value');", self::functionBody('function renderProcessing('));
    }

    /**
     * The card keeps the class every rule of its own is keyed on.
     *
     * Dropped once, in a refactor, and everything went with it — the metadata row's styling, the
     * callout's, the dialog margin, and the rule that hides a Retry button `.btn` would otherwise force
     * back on screen. Close and Retry both appeared beside a running job as a result.
     */
    public function testTheCardKeepsTheClassItsOwnRulesNeed(): void
    {
        self::assertStringContainsString('class="a2t-upload-feedback a2t-processing"', self::card());

        $css = self::css();

        foreach ([
            '.a2t-processing [hidden] { display: none; }',
            '.a2t-processing__meta',
            '.a2t-processing__note',
            '.source-modal .a2t-processing',
        ] as $rule) {
            self::assertStringContainsString($rule, $css, $rule . ' has nothing to apply to without it.');
        }
    }

    /** Both arrangements come from one file, and each surface states which it is. */
    public function testOneFileDrawsBothArrangements(): void
    {
        self::assertStringContainsString('<?php if ($layout === \'steps\'): ?>', self::card());

        $template = self::storeTemplate();
        self::assertSame(1, preg_match_all("/'layout' => 'steps',/", $template), 'Only the upload form.');
        self::assertSame(3, preg_match_all("/'layout' => 'overall',/", $template), 'The three manual cards.');
    }

    // ---------------------------------------------------------------- width

    /** 14. Both dialogs are wide enough for the card, and neither can exceed the viewport. */
    public function testBothDialogsAreWideEnoughAndStillResponsive(): void
    {
        $css = self::css();

        self::assertSame(
            1,
            preg_match('/\.a2t-update-dialog \{ width: min\((\d+)rem, calc\(100vw - 2rem\)\); \}/', $css, $update),
        );
        self::assertGreaterThanOrEqual(44, (int) $update[1], 'About 700px or more on a desktop.');

        self::assertSame(
            1,
            preg_match('/\.a2t-tts-confirm-dialog \{ width: min\((\d+)rem, calc\(100vw - 2rem\)\); \}/', $css, $tts),
        );
        self::assertGreaterThanOrEqual(40, (int) $tts[1], 'It carries the same card now.');
    }

    // ---------------------------------------------------------------- controls

    /**
     * 6. Retry is offered only after a failure, never beside a job that is still running.
     *
     * Two things were once wrong: the button was not gated on the state, and `[hidden]` loses to
     * `.btn { display: inline-flex }` on specificity, so marking it hidden did nothing at all.
     */
    public function testRetryIsOfferedOnlyAfterAFailure(): void
    {
        self::assertStringContainsString(
            "retry.hidden = model.state !== 'failed' || !model.retry;",
            self::functionBody('function renderProcessing('),
        );

        self::assertStringContainsString('.a2t-processing [hidden] { display: none; }', self::css());
    }

    /** Close is always reachable: the card draws one, or the dialog's own head already has it. */
    public function testCloseIsAlwaysReachable(): void
    {
        self::assertStringContainsString('<?php if ($closable): ?>', self::card());
        self::assertStringContainsString("'closable' => false,", self::storeTemplate());

        self::assertStringContainsString(
            "classList.contains('source-modal__head')",
            self::functionBody('function formPartsIn('),
            'The head, and its close control, survive the mode switch.',
        );
    }

    /** A second press is refused while the first is in flight, in both dialogs. */
    public function testDuplicateSubmissionsAreRefused(): void
    {
        self::assertStringContainsString('if (updateBusy) {', self::functionBody('function submitUpdate('));
        self::assertStringContainsString(
            'if (listenBusy || reviewToken === null',
            self::functionBody('function requestGeneration('),
        );
    }

    // ---------------------------------------------------------------- the Details view

    /**
     * 8. Details on a processing recording shows the same card, polling the same endpoint.
     *
     * A job that is not COMPLETED has no fragment to fetch — that endpoint answers 404 — so this reads
     * the status endpoint the Update dialog already follows, and renders from the same model.
     */
    public function testDetailsShowsTheSameCardForAProcessingRecording(): void
    {
        // The body moved into showProgressOn() when asking for a transcript became a second way in: that
        // caller has no button carrying the url, only a server response that named it. `openProgress` is
        // now the thin button adapter, and this is still the one place the card is opened.
        $open = self::functionBody('function showProgressOn(');

        self::assertStringContainsString('showProcessing(reviewDialog, true);', $open);
        self::assertStringContainsString('renderProcessing(reviewDialog, progressModel(', $open);
        self::assertStringContainsString('watchProgress(url);', $open);
        self::assertStringNotContainsString('recallProcessing', $open, 'The server is asked, not storage.');
        self::assertStringContainsString(
            'showProgressOn(',
            self::functionBody('function openProgress('),
            'The button adapter must still reach the card.',
        );

        self::assertStringContainsString('processingViewModel(state, {', self::functionBody('function progressModel('));
        self::assertStringContainsString('$slot->isProcessing()', self::storeTemplate());
    }

    /** The Details poll stops at both end states, and a finished one refreshes the page behind it. */
    public function testTheDetailsPollStopsAndRefreshesOnCompletion(): void
    {
        $watch = self::functionBody('function watchProgress(');

        self::assertStringContainsString('if (model.finished) {', $watch);
        self::assertStringContainsString('stopProgressWatch();', $watch);
        self::assertStringContainsString('window.location.reload();', $watch);
        self::assertStringContainsString("state.status === 'FAILED'", $watch);
    }

    // ---------------------------------------------------------------- close and reopen

    /**
     * 7. Closing stops the view, never the work — and reopening asks the server.
     *
     * The stored entry is a url, an id and a start time. The first thing a restore does is poll, so an
     * entry whose job finished while the window was shut repaints as finished.
     */
    public function testClosingStopsTheViewAndReopeningAsksTheServer(): void
    {
        $script = self::script();

        $close = strpos($script, "updateDialog.addEventListener('close'");
        self::assertNotFalse($close);
        $handler = substr($script, $close, 400);
        self::assertStringContainsString('stopWatching();', $handler);
        self::assertStringNotContainsString('forgetProcessing', $handler, 'A close is not an abandonment.');

        $open = self::functionBody('function openUpdate(');
        self::assertStringContainsString("recallProcessing('update', target.replaces)", $open);
        self::assertStringContainsString('watchReplacement(pending.statusUrl, pending.jobPublicId, 0);', $open);

        // Only a pointer is stored. No status, no stage, no percentage.
        $submit = self::functionBody('function submitUpdate(');
        $stored = substr($submit, (int) strpos($submit, "rememberProcessing('update'"));
        $stored = substr($stored, 0, (int) strpos($stored, '});'));

        self::assertStringContainsString('statusUrl: data.statusUrl,', $stored);

        foreach (['stage', 'percent', 'badge', 'headline'] as $authoritative) {
            self::assertStringNotContainsString(
                $authoritative,
                $stored,
                'The browser stores no ' . $authoritative . ' of its own; the server is the authority.',
            );
        }
    }

    /** Every storage access is wrapped: a private window must not be able to break an upload. */
    public function testStorageFailureCannotBreakAnything(): void
    {
        foreach (['function rememberProcessing(', 'function recallProcessing(', 'function forgetProcessing('] as $fn) {
            self::assertStringContainsString('catch (ignored)', self::functionBody($fn), $fn . ' is unguarded.');
        }

        self::assertStringNotContainsString('window.localStorage', self::script(), 'Per-tab, per-session.');
    }

    /**
     * 12 & 16. One mapping, and it names no mechanism.
     *
     * QUEUED and CLAIMED are the queue and the claim. Neither is work, so both read as "Preparing" and
     * the words themselves never reach a screen.
     */
    public function testOneMappingCoversEveryStageAndLeaksNoWorkerTerminology(): void
    {
        $script = self::script();

        self::assertSame(1, preg_match_all('/var PROCESSING_STAGES = \{/', $script), 'One mapping.');

        foreach (['QUEUED', 'CLAIMED', 'CONVERTING', 'TRANSCRIBING', 'DIARIZING', 'MAPPING_SPEAKERS', 'SAVING'] as $stage) {
            self::assertSame(
                1,
                preg_match('/\b' . $stage . ': \{ step:/', $script),
                $stage . ' has no entry, so it would fall through rather than map.',
            );
        }

        self::assertStringNotContainsString('Queued', self::card());
        self::assertStringNotContainsString("textContent = 'Queued", $script);
    }

    // ---------------------------------------------------------------- nothing left to progress

    /**
     * 3–8. A finished generation shows no bar, no estimate and no clock.
     *
     * The bug: READY passed no `percent`, and the renderer read "no percent" as "indeterminate" — so a
     * finished generation sat behind a bar still travelling left to right, next to a Ready badge and two
     * ticks. The three of them disagreed, and the bar was the one that was wrong.
     *
     * The rule is now one expression and no caller can forget it, because no caller supplies it.
     */
    public function testAFinishedJobWithNoFigureShowsNoBar(): void
    {
        $body = self::functionBody('function renderProcessing(');

        self::assertStringContainsString(
            "var showProgress = model.state === 'working'\n"
            . "            || (model.state === 'done' && typeof model.percent === 'number');",
            $body,
            'Working, or finished with somewhere to have finished at. Nothing else.',
        );

        self::assertStringContainsString('overall.hidden = !showProgress;', $body);
        self::assertStringContainsString('if (bar && overall && showProgress) {', $body, 'And the bar is not touched otherwise.');

        // The estimate and the clock go with it — hidden, not merely emptied, or the row still costs a
        // line and still draws the separator between its two halves.
        self::assertStringContainsString('meta.hidden = model.finished;', $body);
        self::assertStringContainsString('note.hidden = model.finished;', $body);

        self::assertStringContainsString('.a2t-processing__meta[hidden] { display: none; }', self::css());
        self::assertStringContainsString(
            '.a2t-processing [data-a2t-processing-overall][hidden] { display: none; }',
            self::css(),
        );
    }

    /**
     * 1, 2, 3 & 9. AI audio: a bar while generating, none once ready — Generate and Regenerate alike.
     *
     * Both go through the same `showTtsProcessing` and the same `settleTtsPanel`; the only thing that
     * differs between them is the verb in the headline, which the server supplies.
     */
    public function testAiAudioShowsABarOnlyWhileGenerating(): void
    {
        // Generating: no figure, so the bar is indeterminate and travels.
        $working = self::functionBody('function showTtsProcessing(');
        self::assertStringContainsString("state: 'working'", $working);
        self::assertSame(0, preg_match('/percent:/', $working), 'No position in a workflow it does not have.');

        $settle = self::functionBody('function settleTtsPanel(');

        // Ready: still no figure, and now `state: done` — which together are what hide the bar.
        self::assertStringContainsString("badge: 'Ready'", $settle);
        self::assertStringContainsString("state: 'done'", $settle);
        self::assertStringContainsString("detail: 'AI audio is ready to play.'", $settle);

        // 10. Failed shows no bar either: the checklist already says where it stopped.
        self::assertStringContainsString("badge: 'Failed'", $settle);
        self::assertStringContainsString("state: 'failed'", $settle);
        self::assertSame(
            0,
            preg_match('/percent:/', $settle),
            'Neither end state has a figure, so neither draws a bar.',
        );

        // One verb, from the server's own button label, so Regenerate differs only in wording.
        self::assertStringContainsString("ttsVerb = generated.buttonLabel === 'Regenerate'", self::script());
    }

    /**
     * 11. Update Audio keeps its bar, and ends at 100% before the reload.
     *
     * The opposite case and the reason the rule is not simply "hide it when finished": a transcription
     * does have a position to finish at, and the last thing the reader sees before the page turns over
     * should be the thing they were waiting for.
     */
    public function testUpdateAudioKeepsItsBarAndEndsAtFullWidth(): void
    {
        $model = self::functionBody('function processingViewModel(');

        self::assertStringContainsString('percent: 100,', $model, 'A finished transcription ends at 100%.');
        self::assertStringContainsString("badge: 'Completed'", $model);

        // Held briefly, so the success state is seen rather than replaced by a page load.
        self::assertSame(
            1,
            preg_match('/setTimeout\(function \(\) \{ arriveAt\(jobPublicId\); \}, \d+\);/', self::functionBody('function watchReplacement(')),
        );
    }

    /** No stray separator on a card with nothing to separate. */
    public function testTheMetadataSeparatorNeedsBothHalves(): void
    {
        self::assertStringContainsString(
            ".a2t-processing__meta > span + span:not(:empty)::before",
            self::css(),
            'An unconditional separator drew a middle dot on a finished card.',
        );
    }

    /** The estimate is hedged, and absent when there is nothing to base it on. */
    public function testTheEstimateIsApproximateAndOptional(): void
    {
        $body = self::functionBody('function etaWords(');

        self::assertStringContainsString('Usually takes about', $body);
        self::assertStringContainsString('This may take a few minutes.', $body);
        self::assertStringContainsString('!eta || !eta.lowSeconds || !eta.highSeconds', $body);
    }

    /** Success is shown before the page turns over, on both surfaces that reload. */
    public function testSuccessIsVisibleBeforeTheRefresh(): void
    {
        self::assertSame(
            1,
            preg_match('/setTimeout\(function \(\) \{ arriveAt\(jobPublicId\); \}, \d+\);/', self::functionBody('function watchReplacement(')),
        );

        self::assertSame(
            1,
            preg_match('/setTimeout\(function \(\) \{ window\.location\.reload\(\); \}, \d+\);/', self::functionBody('function watchProgress(')),
        );
    }
}
