<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function preg_match;
use function str_contains;
use function strpos;
use function substr;

/**
 * Where the Details dialog's two actions live, and that they stay put.
 *
 * Both claims here are about arrangement, which no functional test notices. A button that moved back
 * under the players would still work; a button that vanished while a worker held the job also still
 * "worked", and that is precisely the bug this pins — the request succeeded and the only visible effect
 * was the loss of the control that had been pressed.
 *
 * @see \App\AudioToText\Web\Job\Store\StoreAudioAsset the bundle these files belong to
 */
final class DetailsHeaderActionsTest extends TestCase
{
    private static function read(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    private static function script(): string
    {
        return self::read('assets/audio-store/audio-store.js');
    }

    private static function stylesheet(): string
    {
        return self::read('assets/audio-store/audio-store.css');
    }

    private static function template(): string
    {
        return self::read('src/AudioToText/Web/Job/Store/template.php');
    }

    /**
     * The Details dialog's header, exactly: from the dialog element to the status line that follows it.
     *
     * Bounded by real landmarks rather than by a character count, so a comment added to the header
     * cannot silently push the close button out of the window and turn a real check into a vacuous one.
     */
    private static function header(): string
    {
        $template = self::template();
        $start = strpos($template, 'a2t-review-dialog');
        self::assertNotFalse($start, 'The Details dialog has moved.');

        $end = strpos($template, 'data-a2t-review-status', $start);
        self::assertNotFalse($end, 'The header is expected to end where the status line begins.');

        return substr($template, $start, $end - $start);
    }

    /** The painter's own body, so a match cannot come from somewhere else in a 2,000-line file. */
    private static function painter(): string
    {
        $script = self::script();
        $start = strpos($script, 'function paintRecordingActions(');
        self::assertNotFalse($start, 'The actions painter has moved.');

        $end = strpos($script, "\n    }\n", $start);
        self::assertNotFalse($end);

        return substr($script, $start, $end - $start);
    }

    /** The container the script fills is inside the dialog's header, beside the way to the full editor. */
    public function testTheActionsAreRenderedInTheDialogHeader(): void
    {
        $header = self::header();

        self::assertStringContainsString('source-modal__head', $header);
        self::assertStringContainsString('data-a2t-review-actions', $header);
        self::assertStringContainsString('data-a2t-full-editor', $header);

        self::assertLessThan(
            strpos($header, 'data-a2t-full-editor'),
            strpos($header, 'data-a2t-review-actions'),
            'The actions come before the full-editor link, so the header reads left to right.',
        );
    }

    /**
     * The close button is a sibling of the actions, not one of them.
     *
     * At narrow widths the actions take a line of their own. A close button inside them would go with
     * them, leaving the corner where every dialog on this page keeps it — on exactly the screens where
     * hunting for it costs most.
     */
    public function testTheCloseButtonIsNotInsideTheActions(): void
    {
        $header = self::header();
        $actions = strpos($header, 'class="a2t-dialog__actions"');
        $close = strpos($header, 'source-modal__close');

        self::assertNotFalse($actions);
        self::assertNotFalse($close);

        // The actions container is closed before the close button begins.
        $between = substr($header, $actions, $close - $actions);
        self::assertStringContainsString('</div>', $between, 'The close button sits outside the actions.');
    }

    /** The row that used to hold these under the players is gone from both the script and the styles. */
    public function testTheSeparateActionsRowIsNoLongerUsed(): void
    {
        self::assertStringNotContainsString(
            'a2t-listen__actions',
            self::script(),
            'The script no longer builds a row of actions beneath the players.',
        );

        self::assertStringNotContainsString(
            'a2t-listen__actions',
            self::stylesheet(),
            'And the rule that laid it out is gone with it, rather than left behind unused.',
        );
    }

    /**
     * The control is drawn on `offered`, and disabled on `canGenerate` — never drawn on `canGenerate`.
     *
     * This is the regression, stated as precisely as it can be from the outside: rendering the button on
     * the same field that decides whether it may be pressed is what made it disappear the moment it was.
     */
    public function testTheGenerateControlIsDrawnOnOfferedAndDisabledOnCanGenerate(): void
    {
        $painter = self::painter();

        self::assertMatchesRegularExpression(
            '/if\s*\(\s*generated\s*&&\s*generated\.offered\s*\)/',
            $painter,
            'Whether the control exists is `offered`.',
        );

        self::assertStringContainsString(
            'disabled = !generated.canGenerate',
            $painter,
            'Whether it may be pressed is `canGenerate`, expressed as disabled rather than as absent.',
        );

        self::assertFalse(
            (bool) preg_match('/if\s*\(\s*generated\s*&&\s*generated\.canGenerate\s*\)/', $painter),
            'Drawing on canGenerate is the bug; it must not come back.',
        );
    }

    /** Both controls go into the header container, and nothing is appended to the panel below. */
    public function testBothControlsArePaintedIntoTheHeaderContainer(): void
    {
        $painter = self::painter();

        self::assertStringContainsString('data-a2t-update-open', $painter);
        self::assertStringContainsString('data-a2t-tts-confirm', $painter);
        self::assertSame(
            2,
            preg_match_all('/reviewActions\.appendChild\(/', $painter),
            'Both controls are appended to the header container.',
        );
        self::assertStringNotContainsString(
            'listen.appendChild',
            $painter,
            'Nothing is added to the player panel any more.',
        );
    }

    /** The watcher's own body. */
    private static function watcher(): string
    {
        $script = self::script();
        $start = strpos($script, 'function watchGenerated(');
        self::assertNotFalse($start, 'The generation watcher has moved.');

        $end = strpos($script, "\n    }\n", $start);
        self::assertNotFalse($end);

        return substr($script, $start, $end - $start);
    }

    /**
     * The header control catches up on its own, through the endpoint the dialog already uses.
     *
     * Nothing pushes the end of a generation to an open page, so without this the dialog sits on
     * "Queued…" until somebody reloads — and the person who pressed the button is exactly the person
     * watching it. Driven by the server's `inFlight`, so it stops when the server says it has stopped
     * rather than after some number of tries this file would have had to choose.
     */
    public function testTheWatcherAsksAgainOnlyWhileTheServerSaysAWorkerHasIt(): void
    {
        $watcher = self::watcher();

        self::assertStringContainsString('generated.inFlight', $watcher, 'The server decides when to stop.');
        self::assertStringContainsString('load(requested)', $watcher, 'Re-reads the dialog\'s own endpoint.');
    }

    /**
     * Re-reading repaints the panel and the header, and leaves the transcript alone.
     *
     * `renderReview` rebuilds every turn. Calling it on a timer would throw away an editor somebody had
     * open, drop a selection mid-merge and jump the scroll — all to report a state change in a strip at
     * the top of the dialog.
     */
    public function testTheWatcherRepaintsThePanelWithoutRebuildingTheTranscript(): void
    {
        $watcher = self::watcher();

        self::assertStringContainsString('paintListen(fresh)', $watcher);
        self::assertStringNotContainsString(
            'renderReview',
            $watcher,
            'The turns are not rebuilt on a timer.',
        );
    }

    /**
     * The optimistic "Queued…" is put back when the request is refused.
     *
     * The header is moved to a disabled "Queued…" the moment the request is sent, so the control the
     * operator is watching does not still read "Regenerate AI Audio" for the round trip. That is a
     * guess, and when the server says no — a stale digest, a generation already under way — the guess
     * has to be undone, or a refusal leaves a permanently disabled button behind it.
     */
    public function testARefusedGenerationPutsTheHeaderControlBack(): void
    {
        $script = self::script();

        $start = strpos($script, 'function requestGeneration(');
        self::assertNotFalse($start, 'The generation request has moved.');

        // Bounded by the helper that follows it, not by a character count — a slice that stopped short
        // would find the first restore, miss the second, and report a half-fixed path as fixed.
        $end = strpos($script, 'function restoreHeaderAction(', $start);
        self::assertNotFalse($end, 'The restore helper is expected to follow the request.');

        $body = substr($script, $start, $end - $start);

        self::assertStringContainsString(
            "textContent = 'Starting…'",
            $body,
            'The header is moved on straight away — and says "Starting", not the name of a queue.',
        );
        self::assertSame(
            2,
            preg_match_all('/restoreHeaderAction\(\)/', $body),
            'And put back on both failure paths: a refusal and a lost connection.',
        );
    }

    /**
     * The narrow layout moves the actions; it never removes them.
     *
     * `display: none` in a media query is the easy way to make a cramped header fit, and it would take
     * the only way to replace a recording away from whoever is on a small screen.
     */
    public function testTheNarrowHeaderKeepsTheActions(): void
    {
        $css = self::stylesheet();

        $start = strpos($css, '@media (max-width: 620px)');
        self::assertNotFalse($start, 'The narrow header rules have moved.');

        $block = substr($css, $start, 700);

        self::assertStringContainsString('a2t-dialog__actions', $block, 'The actions are placed, not dropped.');
        self::assertFalse(
            str_contains($block, 'display: none'),
            'Nothing in the narrow header is hidden.',
        );
    }
}
