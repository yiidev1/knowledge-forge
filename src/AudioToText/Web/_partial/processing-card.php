<?php

declare(strict_types=1);

use Yiisoft\Html\Html;

/**
 * The "Recording progress" card — the one this module uses wherever something is being waited on.
 *
 * ## Why every surface renders this file
 *
 * Five places watch a worker: the upload form, the Update Audio dialog, the Details dialog opened on a
 * recording still being transcribed, and Generate and Regenerate AI audio. They were four different
 * answers to one question. Now there is one structure, and a change to it reaches all of them — which
 * is the only arrangement in which they cannot drift.
 *
 * ## What a step says, and what it refuses to say
 *
 * A step is **done**, **running**, **waiting** or **failed**, and its right-hand value says which in
 * those words. Only a finished step shows a figure, and the figure is always 100% — because that is the
 * only percentage anything here actually knows. A running step's bar carries no `value`, which makes it
 * `:indeterminate` and animates; it does not claim a fraction, because the server publishes a stage and
 * never a proportion of one.
 *
 * ## Hooks are passed in, not assumed
 *
 * The upload form's script was written against its own attribute names and is not this refactor's to
 * rewrite, so each step names the hooks it wants. The upload card passes the names it has always used
 * and its behaviour is untouched; the manual cards pass generic ones and are driven by index.
 *
 * @var Yiisoft\View\WebView $this
 * @var string $title what this card is about, in the heading
 * @var array<string, scalar|bool> $cardAttrs attributes identifying the card to its script
 * @var array<string, scalar|bool> $badgeAttrs attributes for the top-right pill
 * @var string $badgeText the pill's starting words
 * @var list<array{
 *     number: string,
 *     label: string,
 *     detail: string,
 *     value: string,
 *     stepAttrs: array<string, scalar|bool>,
 *     valueAttrs: array<string, scalar|bool>,
 *     barAttrs: array<string, scalar|bool>,
 *     detailAttrs: array<string, scalar|bool>
 * }> $steps in order; each is one numbered row with its own bar
 * @var array<string, scalar|bool>|null $errorAttrs the alert row, or null to omit it
 * @var bool $meta whether to draw the estimate/elapsed row
 * @var bool $note whether to say the window may be closed
 * @var bool $closable whether to draw the close/retry row (a dialog with its own close passes false)
 * @var string $extra markup appended inside the card, already escaped by its caller
 * @var string $layout 'steps' for one bar per step (the upload form's two), or 'overall' for one bar
 *                     and a checklist (a transcription's five stages, which are one job in sequence)
 *
 * Every one is required — no defaults. A card that silently drew the wrong steps, or quietly dropped
 * its own close button, would be wrong in a way nobody would notice; making each surface state its
 * intent is what stops that. (It is also what Psalm needs: a `??=` beside a non-nullable docblock type
 * is a contradiction it reports, and rightly.)
 */

?>
<div class="a2t-upload-feedback a2t-processing"<?= Html::renderTagAttributes($cardAttrs) ?>
     data-a2t-state="idle" hidden>
    <div class="a2t-upload-feedback__heading">
        <strong><?= Html::encode($title) ?></strong>
        <span class="a2t-upload-state"<?= Html::renderTagAttributes($badgeAttrs) ?>><?= Html::encode($badgeText) ?></span>
    </div>

    <?php if ($layout === 'steps'): ?>
        <?php
        // One numbered row per step, each with its own bar. The upload form's arrangement, kept for the
        // upload form: it has two steps and they are genuinely independent — bytes arriving, then a
        // worker converting — so a row each is the honest shape.
        ?>
        <?php foreach ($steps as $step): ?>
            <div class="a2t-upload-step"<?= Html::renderTagAttributes($step['stepAttrs']) ?> data-state="pending">
                <div class="a2t-upload-feedback__label">
                    <span class="a2t-upload-step__title">
                        <span aria-hidden="true"><?= Html::encode($step['number']) ?></span> <?= Html::encode($step['label']) ?>
                    </span>
                    <span<?= Html::renderTagAttributes($step['valueAttrs']) ?> aria-hidden="true"><?= Html::encode($step['value']) ?></span>
                </div>
                <progress class="a2t-upload-progress"<?= Html::renderTagAttributes($step['barAttrs']) ?>
                          max="100" value="0"
                          aria-label="<?= Html::encode($step['label'] . ' progress') ?>"></progress>
                <p class="a2t-upload-step__detail"<?= Html::renderTagAttributes($step['detailAttrs']) ?>
                   role="status"><?= Html::encode($step['detail']) ?></p>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <?php
        // One bar for the whole job, and the stages beneath it as a checklist. A transcription's five
        // stages are one thing happening in sequence, not five things, and five bars made the dialog
        // taller than the screen to say what one bar and five words say.
        //
        // The figure is the workflow's position — which stage of five has been reached — and it moves
        // only when the server reports a different one. Nothing counts it up.
        ?>
        <div class="a2t-upload-step" data-a2t-processing-overall data-state="active">
            <div class="a2t-upload-feedback__label">
                <span class="a2t-upload-step__title">Overall progress</span>
                <span data-a2t-processing-percent aria-hidden="true"></span>
            </div>
            <progress class="a2t-upload-progress" data-a2t-processing-bar max="100" value="0"
                      aria-label="Overall progress"></progress>
        </div>

        <?php if ($steps !== []): ?>
            <ol class="a2t-processing__stages" data-a2t-processing-stages>
                <?php foreach ($steps as $step): ?>
                    <li class="a2t-processing__stage"<?= Html::renderTagAttributes($step['stepAttrs']) ?>
                        data-state="pending">
                        <span class="a2t-processing__mark" aria-hidden="true"></span>
                        <?= Html::encode($step['label']) ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>

        <p class="a2t-upload-step__detail" data-a2t-processing-detail role="status"></p>
    <?php endif; ?>

    <?php if ($errorAttrs !== null): ?>
        <p class="a2t-upload-error"<?= Html::renderTagAttributes($errorAttrs) ?> role="alert" hidden></p>
    <?php endif; ?>

    <?= $extra ?>

    <?php if ($meta): ?>
        <?php // Secondary, below the steps: an approximate range and a measured clock, never inside a bar.?>
        <p class="a2t-processing__meta">
            <span data-a2t-processing-eta></span>
            <span class="a2t-processing__elapsed" data-a2t-processing-elapsed></span>
        </p>
    <?php endif; ?>

    <?php if ($note): ?>
        <p class="a2t-processing__note" data-a2t-processing-note>
            Processing will continue in the background.
        </p>
    <?php endif; ?>

    <?php if ($closable): ?>
        <div class="a2t-confirm__actions a2t-processing__actions">
            <button class="btn btn--sm" type="button" data-a2t-dialog-close>Close</button>
            <button class="btn btn--primary btn--sm" type="button" data-a2t-processing-retry hidden>Retry</button>
        </div>
    <?php endif; ?>
</div>
