<?php

declare(strict_types=1);

use Yiisoft\Html\Html;

/**
 * The two confirmations a correction goes through, shared by both screens that offer one.
 *
 * The correction page and the store page's Details dialog render *this*, so the wording an
 * administrator reads before joining two messages is written once. Two copies would drift, and a
 * confirmation that describes the operation slightly differently on one screen is worse than none.
 *
 * ## The forms are real forms on both screens
 *
 * Same field names, same CSRF token, same `expected_review_count`. The page submits them and follows
 * the redirect; the Details dialog intercepts the submit and sends the identical body by `fetch` so
 * the dialog stays open. That is the only difference between the two, and it is a transport.
 *
 * `$version` is null where the script fills it: the dialog re-reads the conversation after every
 * correction, so the version it must carry changes while the page it sits on does not.
 *
 * @var Yiisoft\View\WebView $this
 * @var string $csrfField the rendered hidden CSRF input
 * @var int|null $version `review_count` to submit, or null to leave for the script
 */

$versionField = (string) Html::hiddenInput(
    'expected_review_count',
    $version === null ? '' : (string) $version,
)->attribute('data-a2t-version', '');
?>
<dialog class="a2t-confirm" data-a2t-merge-dialog>
    <form method="post" data-a2t-merge-form>
        <?= $csrfField ?><?= $versionField ?>
        <input type="hidden" name="direction" data-a2t-merge-direction value="">
        <?php
        // Present only for a partial move. The endpoint treats their absence as "join the whole turn",
        // so the two shapes of the same correction share one form.
?>
        <input type="hidden" name="selection_start" data-a2t-merge-start disabled value="">
        <input type="hidden" name="selection_end" data-a2t-merge-end disabled value="">
        <input type="hidden" name="selection_text" data-a2t-merge-selected disabled value="">

        <h2 class="a2t-confirm__title">Merge these messages?</h2>

        <p class="a2t-confirm__row">
            <span class="a2t-confirm__key">First</span>
            <span class="a2t-confirm__value" data-a2t-merge-first></span>
        </p>
        <p class="a2t-confirm__row">
            <span class="a2t-confirm__key">Second</span>
            <span class="a2t-confirm__value" data-a2t-merge-second></span>
        </p>
        <p class="a2t-confirm__row">
            <span class="a2t-confirm__key">Result</span>
            <span class="a2t-confirm__value" data-a2t-merge-result></span>
        </p>
        <p class="a2t-confirm__note">
            Both turns are by the same speaker in the same role, so joining them changes who said what
            not at all — only how it is broken up. The timings become the span of the two together.
        </p>

        <div class="a2t-confirm__actions">
            <button class="btn btn--sm" type="button" data-a2t-merge-cancel>Cancel</button>
            <button class="btn btn--sm btn--primary" type="submit" data-a2t-merge-confirm>Confirm merge</button>
        </div>
    </form>
</dialog>

<dialog class="a2t-confirm" data-a2t-move-dialog>
    <form method="post" data-a2t-move-form>
        <?= $csrfField ?><?= $versionField ?>
        <input type="hidden" name="selection" data-a2t-move-selection value="">
        <input type="hidden" name="hint" data-a2t-move-hint value="">
        <input type="hidden" name="role" data-a2t-move-role value="">

        <h2 class="a2t-confirm__title">Move this text?</h2>

        <p class="a2t-confirm__row">
            <span class="a2t-confirm__key">Selected</span>
            <span class="a2t-confirm__value" data-a2t-move-preview></span>
        </p>
        <p class="a2t-confirm__row">
            <span class="a2t-confirm__key">From</span>
            <span class="a2t-confirm__value" data-a2t-move-from></span>
        </p>
        <p class="a2t-confirm__row">
            <span class="a2t-confirm__key">To</span>
            <span class="a2t-confirm__value" data-a2t-move-to></span>
        </p>
        <p class="a2t-confirm__note" data-a2t-move-note hidden></p>

        <div class="a2t-confirm__actions">
            <button class="btn btn--sm" type="button" data-a2t-move-cancel>Cancel</button>
            <button class="btn btn--sm btn--primary" type="submit" data-a2t-move-confirm>Confirm move</button>
        </div>
    </form>
</dialog>
