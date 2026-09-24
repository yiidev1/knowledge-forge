<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function implode;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function str_contains;
use function trim;

/**
 * The two ways a stylesheet can defeat "this is hidden", pinned.
 *
 * These exist because of a bug the rest of the suite could not see. Every markup assertion passed, the
 * endpoints answered correctly, and the page was still wrong in the browser: one `display: flex` on a
 * bare dialog class painted a **closed** `<dialog>` as a block sitting underneath the page, with no
 * backdrop and an empty body. Nothing about the HTML was wrong, so nothing about the HTML caught it.
 *
 * Both checks below are the same rule seen from two sides — an author `display` outranks the
 * user-agent's `display: none`, so any element whose visibility is controlled by an attribute needs a
 * companion rule that says so.
 *
 * Static checks on the stylesheet and script text. They prove nothing about how a browser paints; they fail
 * loudly on the one mistake that has already been made once.
 */
final class StoreDialogStylesheetTest extends TestCase
{
    /**
     * Elements the store page's script shows and hides by setting `.hidden`.
     *
     * Each has a `display` in one of the stylesheets, so each needs a `[hidden]` companion or the
     * attribute does nothing at all.
     */
    private const TOGGLED = [
        'a2t-tabs',
        'source-modal__body',
        'a2t-turn__merge',
        'a2t-upload-feedback',
        'a2t-upload-result',
    ];

    /**
     * **A `<dialog>` class may size itself. It may not give itself a `display`.**
     *
     * The user-agent stylesheet is `dialog { display: none }` with `dialog[open] { display: block }`.
     * An author rule on the bare class beats that `none`, so the dialog paints while closed — and a
     * closed dialog has never entered the top layer, so it lays out as an absolutely positioned block
     * after the page content, with no backdrop and no centring. That is exactly what happened.
     *
     * Qualifying the selector with `[open]` is the fix and the rule: sizing on the bare class is fine,
     * `display` never is.
     */
    public function testNoDialogClassGivesItselfADisplayWhileClosed(): void
    {
        $offenders = [];

        foreach ($this->rules($this->storeCss()) as [$selector, $body]) {
            // A whole class token ending in `-dialog`, so `.a2t-dialog__actions` — a group of buttons
            // inside a dialog head — is not mistaken for the dialog itself.
            if (preg_match('/\.a2t-[a-z-]*-dialog(?![\w-])/', $selector) !== 1) {
                continue;
            }

            // A descendant rule styles something *inside* the dialog and is not this hazard.
            if (preg_match('/-dialog(?:\[[^\]]*\])?\s+\S/', $selector) === 1) {
                continue;
            }

            if (preg_match('/(?:^|[;{\s])display\s*:/', $body) !== 1) {
                continue;
            }

            if (!str_contains($selector, '[open]')) {
                $offenders[] = $selector;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A dialog class must not set `display` unless the selector is qualified by [open] — "
                . "otherwise the closed dialog paints as a block under the page:\n"
                . implode("\n", $offenders),
        );
    }

    /**
     * Anything the script hides with `.hidden` must have a `[hidden]` rule wherever it has a display.
     *
     * `display: flex` on a class outranks the `hidden` attribute's `display: none`, so without the
     * companion the attribute is decorative — the element stays on screen for ever. The empty tab bar
     * above a single-recording transcript was this, quietly.
     */
    public function testEveryElementTheScriptHidesCanActuallyBeHidden(): void
    {
        $css = $this->storeCss() . "\n" . $this->adminCss();
        $unhideable = [];

        foreach (self::TOGGLED as $class) {
            $hasDisplay = false;

            foreach ($this->rules($css) as [$selector, $body]) {
                if (!str_contains($selector, '.' . $class)) {
                    continue;
                }

                if (str_contains($selector, '[hidden]')) {
                    // The companion exists; whatever else this class declares is safe.
                    continue 2;
                }

                if (preg_match('/(?:^|[;{\s])display\s*:\s*(?!none)/', $body) === 1) {
                    $hasDisplay = true;
                }
            }

            if ($hasDisplay) {
                $unhideable[] = '.' . $class;
            }
        }

        $this->assertSame(
            [],
            $unhideable,
            "These are given a display and are hidden by attribute, so they need a `[hidden]` "
                . "companion rule or they never hide:\n" . implode("\n", $unhideable),
        );
    }

    /**
     * The script opens dialogs with `showModal()`.
     *
     * `show()` and the `open` attribute both produce a visible dialog that is **not** in the top layer
     * — no backdrop, no centring, no Escape. Only `showModal()` does, and that difference is the whole
     * bug this file was written for.
     *
     * A source assertion, not a behavioural one: this suite cannot run a browser, so it pins the call
     * and cannot prove what the call does.
     */
    public function testTheScriptOpensDialogsInTheTopLayer(): void
    {
        $js = $this->storeJs();

        $this->assertStringContainsString('dialog.showModal()', $js);
        // Reopening a dialog that is open but not modal, instead of leaving it as a block in the page.
        $this->assertStringContainsString("dialog.matches(':modal')", $js);
        $this->assertStringNotContainsString('dialog.show()', $js);
    }

    /** No dialog fetches without saying so, and none reports a failure by showing nothing. */
    public function testEveryFetchingDialogHasALoadingAndAFailureState(): void
    {
        $js = $this->storeJs();

        $this->assertStringContainsString("'Loading transcript…'", $js);
        $this->assertStringContainsString('No original transcript is available', $js);
        // Every dialog that fetches hands its failure a way to try again — Details, Original
        // transcript, Generate Text to Audio and Manage Audio. Counted rather than listed because the
        // thing worth catching is a *new* fetching dialog that quietly has no retry.
        $this->assertSame(4, preg_match_all('/\bfail\(\w+Status,/', $js));
        foreach (['reviewStatus', 'transcriptStatus', 'ttsStatus', 'manageStatus'] as $status) {
            $this->assertMatchesRegularExpression(
                '/\bfail\(' . $status . ',/',
                $js,
                $status . ' must report a failure the reader can retry.',
            );
        }
    }

    /**
     * The Details dialog runs the correction page's controls, it does not reimplement them.
     *
     * Every rule an administrator can feel — what selecting a message means, what Cancel restores,
     * which two turns a merge will join — lives in `KFReviewTurns` in admin.js and is called from
     * both screens. A private copy in this file would be a second editor that starts identical and
     * stops being so, which is the whole reason the module exists.
     */
    public function testTheDetailsDialogDrivesTheSharedCorrectionControls(): void
    {
        $js = $this->storeJs();

        foreach ([
            'turns.selectionInsideOneTurn()',
            'turns.showControlsFor(',
            'turns.openEditor(',
            'turns.closeEditor(',
            'turns.openMove(',
            'turns.openMerge(',
            'turns.endConfirm(',
        ] as $call) {
            $this->assertStringContainsString($call, $js, $call . ' must come from the shared module.');
        }

        // The rules themselves must not be restated here.
        $this->assertStringNotContainsString('function selectionInsideOneTurn', $js);
        $this->assertStringNotContainsString('function openEditor', $js);
        $this->assertStringNotContainsString('isCollapsed', $js);
    }

    /**
     * The dialog offers what the correction page offers, and nothing it does not.
     *
     * Split is the one worth naming: the route exists and the endpoint would accept it, but that page
     * shows the control only inside its `<noscript>` fallback — so a scripting browser has never been
     * offered one, and the dialog must not be the first place it appears.
     */
    public function testTheDetailsDialogAddsNoControlTheReviewPageDoesNotHave(): void
    {
        $js = $this->storeJs();

        // The page's own wording and hooks.
        $this->assertStringContainsString('Merge this message:', $js);
        $this->assertStringContainsString('With previous', $js);
        $this->assertStringContainsString('With next', $js);
        $this->assertStringContainsString('data-a2t-merge-with', $js);

        // The modal-only design that replaced them for a while.
        $this->assertStringNotContainsString('Join this message', $js);
        $this->assertStringNotContainsString('Split after', $js);
        $this->assertStringNotContainsString('splitPoints', $js);
        $this->assertStringNotContainsString("iconButton('more'", $js);
    }

    /**
     * The revision trail is fetched, not rebuilt.
     *
     * A revision's Before/After arrangement, its merge note and the wording of every summary live in
     * `_partial/review-history.php`. The dialog asks the server for that partial's own output; a
     * `createElement` here for any of it would be the template written a second time.
     */
    public function testTheDialogFetchesTheRevisionTrailRatherThanRebuildingIt(): void
    {
        $js = $this->storeJs();

        $this->assertStringContainsString('data.urls.history', $js);
        $this->assertStringContainsString('data-a2t-history-dialog', $js);
        // The dialog's own words belong to the partial, so none of them may be written here.
        $this->assertStringNotContainsString('What was corrected', $js);
        $this->assertStringNotContainsString('a2t-history__event', $js);
        $this->assertStringNotContainsString('a2t-history__turn', $js);
    }

    /**
     * The merge strip is built hidden, and only the selection reveals it.
     *
     * On the correction page a turn's merge controls are `hidden` in the markup and unhidden for the
     * one turn whose words are highlighted. Built visible here, every message in the dialog would
     * carry a pair of buttons and the conversation would read as a form again.
     */
    public function testTheMergeStripIsBuiltHidden(): void
    {
        $js = $this->storeJs();

        $this->assertMatchesRegularExpression(
            '/data-a2t-merge-controls.*?\n.*?controls\.hidden = true;/s',
            $js,
            'The merge controls must start hidden, as they do on the correction page.',
        );
    }

    /**
     * A move from the dialog is the move the correction page performs.
     *
     * That page's drag ends by filling a confirmation whose action is the turn's `move-text` URL and
     * whose selection is the whole rendered turn. Posting to the simpler `move` route instead would
     * be a second operation writing a different audit row for the same intent.
     */
    public function testTheDialogMovesThroughTheRouteTheReviewPageUses(): void
    {
        $js = $this->storeJs();
        $fragment = (string) file_get_contents(
            $this->root() . '/src/AudioToText/Web/Job/Review/Fragment/Action.php',
        );

        $this->assertStringContainsString('JOB_REVIEW_MOVE_TEXT', $fragment);
        $this->assertStringNotContainsString('JOB_REVIEW_SPLIT', $fragment);
        $this->assertStringContainsString('turn.urls.moveText', $js);
    }

    /**
     * Whether a message has history is TurnLineage's answer, not an appearance.
     *
     * `edited` says a turn's wording differs from the machine's; `hasHistory()` says the audit trail
     * has something to show for it. A revert clears the second and not the first, so reading the
     * wrong one would offer a clock icon that opens an empty dialog.
     */
    public function testTheHistoryIconFollowsTheAuditTrailRatherThanTheEditedFlag(): void
    {
        $fragment = (string) file_get_contents(
            $this->root() . '/src/AudioToText/Web/Job/Review/Fragment/Action.php',
        );

        $this->assertStringContainsString("'hasHistory' => \$turn->hasHistory()", $fragment);
        $this->assertStringContainsString('if (turn.hasHistory)', $this->storeJs());
    }

    /**
     * @return list<array{string, string}>
     */
    private function rules(string $css): array
    {
        // Comments first, and not as tidiness: without this a comment is read as part of the next
        // rule's selector, and a comment that happens to mention `[hidden]` makes the check below
        // pass for a rule that has no such companion. This file's own comments did exactly that.
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);

        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $matches, PREG_SET_ORDER);

        $rules = [];

        foreach ($matches as $match) {
            $selector = trim($match[1]);

            // `@media (...)` and friends: the block before the brace is a query, not a selector.
            if ($selector === '' || $selector[0] === '@') {
                continue;
            }

            $rules[] = [$selector, $match[2]];
        }

        return $rules;
    }

    private function storeCss(): string
    {
        return (string) file_get_contents($this->root() . '/assets/audio-store/audio-store.css');
    }

    private function adminCss(): string
    {
        return (string) file_get_contents($this->root() . '/assets/main/admin.css');
    }

    private function storeJs(): string
    {
        return (string) file_get_contents($this->root() . '/assets/audio-store/audio-store.js');
    }

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
