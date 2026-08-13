<?php

declare(strict_types=1);

use App\Chat\Domain\ChatAnswerScore;
use Yiisoft\Html\Html;

/**
 * "Would you like to rate this answer?" — the one scoring control, shared by all four chat surfaces.
 *
 * Rendered once per active assistant answer, below its citations. The three server-rendered states come
 * straight from {@see \App\Chat\Web\MessageScoreView}:
 *
 *   null        → the Yes / No prompt
 *   dismissed   → a quiet "Rate this answer" link, so an accidental No is recoverable
 *   rated       → "✓ Score saved: N/10" with Change
 *
 * The slider panel itself is always in the DOM and revealed by a class, exactly like the inline edit form;
 * `admin.js` toggles it. Nothing here submits on its own — the score is written only by "Save score", so
 * dragging the slider never records anything.
 *
 * @var Yiisoft\View\WebView $this
 * @var int $messageId
 * @var ChatAnswerScore|null $state
 * @var string $scoreUrl
 * @var string $dismissUrl
 * @var string $csrfField
 */

$isRated = $state !== null && $state->isRated();
$isDismissed = $state !== null && $state->isDismissed();
// A new rating opens mid-scale; changing one opens on the score already given.
$initialValue = $isRated ? (int) $state->score : 5;
$outputId = 'score-value-' . $messageId;
$commentId = 'score-comment-' . $messageId;
$inputId = 'score-input-' . $messageId;

/**
 * Presentation-only banding. The colour is never the sole carrier of meaning — the word is always shown
 * beside the number — so this stays readable without colour perception.
 *
 * Mirrored in `assets/main/admin.js` (`scoreBand`) for live updates while dragging; keep the two in step.
 */
$band = static fn(int $score): array => match (true) {
    $score <= 3 => ['poor', 'Poor'],
    $score <= 6 => ['fair', 'Fair'],
    $score <= 8 => ['good', 'Good'],
    default => ['excellent', 'Excellent'],
};

[$initialBand, $initialLabel] = $band($initialValue);
?>
<div class="chat-msg__score" data-score-panel
     data-score-band="<?= Html::encode($initialBand) ?>"
     data-score-value="<?= $initialValue ?>">
    <?php if ($isRated): ?>
        <div class="chat-msg__score-saved" data-score-saved>
            <span class="chat-msg__score-badge"><?= (int) $state->score ?>/10 · <?= Html::encode($initialLabel) ?></span>
            <?php if ($state->hasComment()): ?>
                <?php /* A marker only — the note itself belongs in the editor, not printed into the thread. */ ?>
                <span class="chat-msg__score-note-flag">· Comment added</span>
            <?php endif; ?>
            <button type="button" class="chat-msg__score-edit" data-score-open
                    title="Change score" aria-label="Change score">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>
            </button>
        </div>
    <?php elseif ($isDismissed): ?>
        <div class="chat-msg__score-saved" data-score-saved>
            <button type="button" class="chat-msg__score-link" data-score-open>Rate this answer</button>
        </div>
    <?php else: ?>
        <div class="chat-msg__score-ask" data-score-ask>
            <span class="chat-msg__score-question">Would you like to rate this answer?</span>
            <button type="button" class="btn btn--secondary btn--sm" data-score-open>Yes</button>
            <form method="post" class="chat-msg__score-dismiss" action="<?= Html::encode($dismissUrl) ?>">
                <?= $csrfField ?>
                <button type="submit" class="btn btn--secondary btn--sm">No</button>
            </form>
        </div>
    <?php endif; ?>

    <form method="post" class="chat-msg__score-form" data-score-form action="<?= Html::encode($scoreUrl) ?>">
        <?= $csrfField ?>
        <label class="chat-msg__score-label" for="<?= Html::encode($inputId) ?>">
            How accurate was this answer?
        </label>

        <div class="chat-msg__score-slider">
            <span class="chat-msg__score-bound" aria-hidden="true">1</span>
            <input
                type="range"
                id="<?= Html::encode($inputId) ?>"
                name="score"
                min="1"
                max="10"
                step="1"
                value="<?= $initialValue ?>"
                data-score-range
                aria-describedby="<?= Html::encode($outputId) ?>">
            <span class="chat-msg__score-bound" aria-hidden="true">10</span>
            <output class="chat-msg__score-output" id="<?= Html::encode($outputId) ?>"
                    for="<?= Html::encode($inputId) ?>"><?= $initialValue ?>/10 · <?= Html::encode($initialLabel) ?></output>
        </div>

        <div class="chat-msg__score-scale" aria-hidden="true">
            <span>1–3 Poor</span>
            <span>4–6 Fair</span>
            <span>7–8 Good</span>
            <span>9–10 Excellent</span>
        </div>

        <?php /* Shown only for the red band. `admin.js` toggles the same attribute the server renders, so a
                 saved 2/10 opens with its note already visible and a slide up to 4 hides it again. */ ?>
        <div class="chat-msg__score-comment">
            <label class="chat-msg__score-comment-label" for="<?= Html::encode($commentId) ?>">
                What was wrong? (optional)
            </label>
            <input class="field__control chat-msg__score-comment-input"
                   type="text"
                   id="<?= Html::encode($commentId) ?>"
                   name="feedback_comment"
                   maxlength="500"
                   placeholder="Add a short comment…"
                   value="<?= Html::encode((string) $state?->feedbackComment) ?>">
        </div>

        <div class="chat-msg__score-actions">
            <button type="submit" class="btn btn--primary btn--sm chat-msg__score-save">✓ Save</button>
            <button type="button" class="btn btn--secondary btn--sm chat-msg__score-save" data-score-cancel>Cancel</button>
        </div>
    </form>
</div>
