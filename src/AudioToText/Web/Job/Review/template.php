<?php

declare(strict_types=1);

use App\AudioToText\Domain\Speaker\MergeRefusal;
use App\AudioToText\Domain\SpeakerRole;
use App\AudioToText\Domain\TranscriptionJob;
use App\AudioToText\Web\AudioToTextRoute;
use App\AudioToText\Web\Job\Review\ReviewPageView;
use App\AudioToText\Web\Job\Review\ReviewTurnView;
use App\Shared\Application\Time\AppTimeZone;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\Csrf;

/**
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var Csrf $csrf
 * @var TranscriptionJob $job
 * @var ReviewPageView $page
 * @var AppTimeZone $appTimeZone
 */

$this->setTitle('Correct speakers');
$this->setParameter('breadcrumbs', [
    ['label' => 'Audio to Text', 'route' => AudioToTextRoute::PAGE],
    ['label' => 'Conversions', 'route' => AudioToTextRoute::JOBS],
    ['label' => 'Correct speakers'],
]);

$csrfField = (string) $csrf->hiddenInput();
$conversationUrl = $urlGenerator->generate(AudioToTextRoute::JOB_CONVERSATION, ['publicId' => $job->publicId]);
$jobUrl = $urlGenerator->generate(AudioToTextRoute::JOB, ['publicId' => $job->publicId]);

$turnUrl = static fn(string $route, int $index): string => $urlGenerator->generate(
    $route,
    ['publicId' => $job->publicId, 'index' => $index],
);
$pageUrl = static fn(string $route): string => $urlGenerator->generate(
    $route,
    ['publicId' => $job->publicId],
);

// Every form carries the version the page was rendered from. The service compares it in the same
// statement that writes, so two administrators cannot both succeed from the same starting point.
$version = static fn(): string => (string) Html::hiddenInput('expected_review_count', (string) $page->version);

// Inline SVG rather than an icon font or a sprite: the CSP is `default-src 'self'` with no external
// origins, and markup needs no request at all.
$icon = static function (string $paths, string $label): string {
    return '<svg class="a2t-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
        . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . $paths . '</svg><span class="a2t-sr">' . Html::encode($label) . '</span>';
};
$pencil = '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>';
// Six dots, the handle everyone already reads as "drag me".
$grip = '<circle cx="9" cy="6" r="1.4"/><circle cx="15" cy="6" r="1.4"/>'
    . '<circle cx="9" cy="12" r="1.4"/><circle cx="15" cy="12" r="1.4"/>'
    . '<circle cx="9" cy="18" r="1.4"/><circle cx="15" cy="18" r="1.4"/>';
?>
<div class="a2t-chat a2t-review" data-a2t-review>
    <div class="a2t-chat__header">
        <div class="a2t-chat__heading">
            <h1 class="a2t-chat__title">Correct speakers</h1>
            <p class="a2t-chat__subtitle"><?= Html::encode($job->originalFilename) ?></p>
        </div>
        <div class="a2t-chat__actions">
            <a class="btn" href="<?= Html::encode($conversationUrl) ?>">Back to conversation</a>
            <a class="btn" href="<?= Html::encode($jobUrl) ?>">Full conversion details</a>
        </div>
    </div>

    <?php
    // One compact row rather than a stacked block: the standing note, the current state and the two
    // page-level actions all fit on a line, which leaves the conversation itself the height.
?>
    <div class="a2t-review__notice">
        <p class="a2t-review__lede">
            The original transcription is never changed — corrections are stored separately, and every
            one is recorded with your name against it.
        </p>

        <div class="a2t-review__status">
            <?php if ($page->confirmedAt !== null): ?>
                <span class="a2t-review__state a2t-review__state--confirmed">
                    Roles confirmed by
                    <strong><?= Html::encode($page->confirmedByUsername ?? 'an administrator') ?></strong>
                    on <?= Html::encode($appTimeZone->format($page->confirmedAt, 'M j, Y g:i A T')) ?>.
                </span>
            <?php elseif ($page->rolesPublished): ?>
                <span class="a2t-review__state">
                    The system separated these speakers confidently. Corrections here keep those labels.
                </span>
            <?php else: ?>
                <span class="a2t-review__state a2t-review__state--unconfirmed">
                    The system could not tell which speaker is the agent. Your corrections are saved,
                    but the conversation stays labelled by speaker until you confirm the roles.
                </span>
            <?php endif; ?>

            <?php if ($page->canConfirm): ?>
                <form method="post" action="<?= Html::encode($pageUrl(AudioToTextRoute::JOB_REVIEW_CONFIRM)) ?>">
                    <?= $csrfField ?><?= $version() ?>
                    <button class="btn btn--sm btn--primary" type="submit">Confirm speaker roles</button>
                </form>
            <?php elseif ($page->confirmBlockedReason !== null): ?>
                <button class="btn btn--sm" type="button" disabled>Confirm speaker roles</button>
                <span class="a2t-review__hint"><?= Html::encode($page->confirmBlockedReason) ?></span>
            <?php endif; ?>

            <?php if ($page->isReviewed): ?>
                <form method="post"
                      action="<?= Html::encode($pageUrl(AudioToTextRoute::JOB_REVIEW_REVERT)) ?>"
                      data-confirm="Discard all corrections and return to the system's original result? This is recorded.">
                    <?= $csrfField ?><?= $version() ?>
                    <button class="btn btn--sm btn--danger" type="submit">Discard all corrections</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="a2t-chat__scroll" data-a2t-scroll>
        <div class="a2t-thread">
            <?php foreach ($page->turns as $turn): ?>
                <?php
            /** @var ReviewTurnView $turn */
            $range = $turn->timing->rangeLabel();
                $delay = $turn->timing->delayLabel();
                $other = $turn->isAgent() ? SpeakerRole::CUSTOMER : SpeakerRole::AGENT;
                $mergesIfMoved = $other === SpeakerRole::AGENT
                    ? $turn->mergesIfMovedToAgent
                    : $turn->mergesIfMovedToCustomer;

                // "ok" wherever there is a neighbour on that side, and absent where there is not.
                // A manual merge asks nothing else of the two turns.
                $mergeAttr = static function (MergeRefusal $refusal, string $name): string {
                    return $refusal === MergeRefusal::NoNeighbour ? '' : ' ' . $name . '="ok"';
                };
                ?>
                <div class="a2t-turn <?= Html::encode($turn->side->modifier()) ?><?=
                    $turn->confirmed ? '' : ' a2t-turn--unconfirmed' ?>"
                     data-a2t-turn="<?= $turn->index ?>"
                     data-a2t-role="<?= Html::encode($turn->role->value) ?>"
                     data-a2t-label="<?= Html::encode($turn->label) ?>"
                     data-a2t-target-role="<?= Html::encode($other->value) ?>"
                     data-a2t-target-label="<?= Html::encode($other->label()) ?>"
                     data-a2t-merges="<?= $mergesIfMoved ? '1' : '0' ?>"
                     data-a2t-text-value="<?= Html::encode($turn->rawText) ?>"
                     data-a2t-move-url="<?= Html::encode($turnUrl(AudioToTextRoute::JOB_REVIEW_MOVE_TEXT, $turn->index)) ?>"
                     data-a2t-merge-url="<?= Html::encode($turnUrl(AudioToTextRoute::JOB_REVIEW_MERGE, $turn->index)) ?>"
                     <?= $mergeAttr($turn->mergeWithPrevious, 'data-a2t-merge-prev') ?>
                     <?= $mergeAttr($turn->mergeWithNext, 'data-a2t-merge-next') ?>>
                    <div class="a2t-bubble">
                        <span class="a2t-turn__who"><?= Html::encode($turn->label) ?></span>
                        <span class="a2t-turn__text" data-a2t-text><?= Html::encode($turn->text) ?></span>
                        <?php if ($range !== null || $delay !== null || $turn->edited || $turn->approx): ?>
                            <span class="a2t-turn__meta">
                                <?php if ($range !== null): ?>
                                    <span class="a2t-turn__time"<?= $turn->approx
                                        ? ' title="Approximate: this boundary was set by hand, so both halves keep the original turn\'s timing."'
                                        : '' ?>><?= Html::encode($range) ?></span>
                                <?php endif; ?>
                                <?php if ($delay !== null): ?>
                                    <span class="a2t-turn__delay"><?= Html::encode($delay) ?></span>
                                <?php endif; ?>
                                <?php if ($turn->edited): ?>
                                    <span class="a2t-turn__flag">edited</span>
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>

                        <?php
                        // Both controls in one group on the message's own side: the handle first, the
                        // pencil behind it. They do nothing without JavaScript, so they stay hidden
                        // until the script announces itself — the plain forms below are the only
                        // controls when it does not.
                        //
                        // The handle is a button rather than a decorative span so it is reachable by
                        // keyboard and announces itself; the script drives it with pointer events,
                        // which cover mouse, pen and touch through one path where HTML5
                        // drag-and-drop would leave touch unsupported.
                ?>
                        <span class="a2t-turn__tools" data-a2t-tools hidden>
                            <button class="a2t-iconbtn a2t-iconbtn--grip" type="button" data-a2t-grip
                                    title="Drag to move this message to the <?= Html::encode($other->label()) ?>"><?= $icon(
                                        $grip,
                                        'Drag to move this message to the ' . $other->label(),
                                    ) ?></button>
                            <button class="a2t-iconbtn" type="button" data-a2t-edit
                                    title="Correct the wording"><?= $icon($pencil, 'Correct the wording') ?></button>
                        </span>
                    </div>

                    <?php // The inline editor JavaScript reveals; it posts the same form as the fallback.?>
                    <form class="a2t-turn__editor" data-a2t-editor hidden method="post"
                          action="<?= Html::encode($turnUrl(AudioToTextRoute::JOB_REVIEW_TEXT, $turn->index)) ?>">
                        <?= $csrfField ?><?= $version() ?>
                        <textarea class="field__control a2t-turn__textarea" name="text" rows="3"
                                  data-a2t-editor-text aria-label="Corrected wording"><?= Html::encode($turn->text) ?></textarea>
                        <div class="a2t-turn__editor-actions">
                            <button class="btn btn--sm" type="button" data-a2t-edit-cancel>Cancel</button>
                            <button class="btn btn--sm btn--primary" type="submit" data-a2t-edit-save>Save</button>
                        </div>
                    </form>

                    <?php
                    // Shown only while this turn is selected, so the thread is not permanently lined
                    // with buttons. A manual merge needs nothing but a neighbour, so the only reason a
                    // direction is unavailable is that there is no turn on that side — and then the
                    // button is simply absent rather than present and refusing.
                    $mergeButton = static function (
                        MergeRefusal $refusal,
                        string $direction,
                        string $label,
                    ): string {
                        if ($refusal === MergeRefusal::NoNeighbour) {
                            return '';
                        }

                        return '<button class="a2t-mergebtn" type="button" data-a2t-merge-with="'
                            . Html::encode($direction) . '">' . Html::encode($label) . '</button>';
                    };
                ?>
                    <div class="a2t-turn__merge" data-a2t-merge-controls hidden>
                        <div class="a2t-turn__merge-row">
                            <span class="a2t-turn__merge-label">Merge this message:</span>
                            <?= $mergeButton($turn->mergeWithPrevious, 'previous', 'With previous') ?>
                            <?= $mergeButton($turn->mergeWithNext, 'next', 'With next') ?>
                        </div>
                    </div>

                    <?php
                // The no-JavaScript path: plain forms, one action each, exactly what this page did
                // before the drag handle existed.
                //
                // Inside <noscript> rather than hidden by a class the script adds. A browser with
                // scripting on does not build these elements at all, so the enhanced layout is what
                // paints first; hiding them afterwards meant the big buttons were briefly on screen
                // and the bubbles jumped once the script caught up. The CSP forbids inline scripts
                // and inline styles, so there is no earlier hook than the parser itself.
                ?>
                    <noscript>
                    <div class="a2t-turn__fallback" data-a2t-fallback>
                        <form method="post" action="<?= Html::encode($turnUrl(AudioToTextRoute::JOB_REVIEW_MOVE, $turn->index)) ?>">
                            <?= $csrfField ?><?= $version() ?>
                            <?= Html::hiddenInput('role', $other->value) ?>
                            <button class="btn btn--sm" type="submit">
                                Move to <?= Html::encode($other->label()) ?>
                            </button>
                        </form>

                        <details class="a2t-turn__advanced">
                            <summary>Advanced</summary>

                            <form method="post" action="<?= Html::encode($turnUrl(AudioToTextRoute::JOB_REVIEW_TEXT, $turn->index)) ?>">
                                <?= $csrfField ?><?= $version() ?>
                                <label class="a2t-turn__advanced-label">Correct the wording</label>
                                <textarea class="field__control" name="text" rows="3"><?= Html::encode($turn->text) ?></textarea>
                                <button class="btn btn--sm" type="submit">Save wording</button>
                            </form>

                            <?php if ($turn->canSplit()): ?>
                                <form method="post" action="<?= Html::encode($turnUrl(AudioToTextRoute::JOB_REVIEW_SPLIT, $turn->index)) ?>">
                                    <?= $csrfField ?><?= $version() ?>
                                    <label class="a2t-turn__advanced-label">Split this turn</label>
                                    <div class="a2t-review-split__points">
                                        <?php
                                    $sentences = $turn->sentenceSplitPoints();
                                $primary = $sentences === [] ? $turn->splitPoints : $sentences;
                                ?>
                                        <?php foreach ($primary as $point): ?>
                                            <label class="a2t-review-split__point">
                                                <input type="radio" name="offset" value="<?= $point->offset ?>">
                                                after &ldquo;<?= Html::encode($point->after) ?>&rdquo;
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <button class="btn btn--sm" type="submit">Split here</button>
                                </form>
                            <?php endif; ?>

                            <?php foreach ([
                                ['direction' => 'previous', 'label' => 'Join with turn above', 'refusal' => $turn->mergeWithPrevious],
                                ['direction' => 'next', 'label' => 'Join with turn below', 'refusal' => $turn->mergeWithNext],
                            ] as $merge): ?>
                                <?php
                                /** @var MergeRefusal $refusal */
                                $refusal = $merge['refusal'];

                                if ($refusal === MergeRefusal::NoNeighbour) {
                                    continue;
                                }
                                ?>
                                <?php if ($refusal->isAllowed()): ?>
                                    <form method="post" action="<?= Html::encode($turnUrl(AudioToTextRoute::JOB_REVIEW_MERGE, $turn->index)) ?>">
                                        <?= $csrfField ?><?= $version() ?>
                                        <?= Html::hiddenInput('direction', $merge['direction']) ?>
                                        <button class="btn btn--sm" type="submit"><?= Html::encode($merge['label']) ?></button>
                                    </form>
                                <?php else: ?>
                                    <p class="a2t-turn__refused">
                                        <button class="btn btn--sm" type="button" disabled>
                                            <?= Html::encode($merge['label']) ?>
                                        </button>
                                        <span class="a2t-review-tools__why"><?= Html::encode((string) $refusal->reason()) ?></span>
                                    </p>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </details>
                    </div>
                    </noscript>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php // Shown by the script only once the reader has scrolled away from the newest turn.?>
    <button class="a2t-chat__jump" type="button" data-a2t-jump hidden>&darr; Jump to latest</button>
</div>

<?php
// One form for the whole page, its action set by the script to whichever turn is being moved. The
// confirmation is a real submit, so CSRF, the version check and the redirect are all unchanged from
// every other control here — nothing about a move goes through a separate JSON path.
?>
<dialog class="a2t-confirm" data-a2t-merge-dialog>
    <form method="post" data-a2t-merge-form>
        <?= $csrfField ?><?= $version() ?>
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
        <?= $csrfField ?><?= $version() ?>
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
