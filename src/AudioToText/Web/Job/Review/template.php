<?php

declare(strict_types=1);

use App\AudioToText\Application\SpokenPrice;
use App\AudioToText\Domain\AudioStore;
use App\AudioToText\Domain\Speaker\MergeRefusal;
use App\AudioToText\Domain\SpeakerRole;
use App\AudioToText\Domain\TranscriptionJob;
use App\AudioToText\Web\AudioToTextIcons;
use App\AudioToText\Web\AudioToTextViews;
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
 * @var AudioStore|null $store the store this job was uploaded against, or null when it was not
 * @var AppTimeZone $appTimeZone
 */

$this->setTitle('Correct speakers');
// The store sits in the trail only when the job was uploaded against one — the same conditional shape
// the conversion page uses, so a job with no store keeps exactly the trail it has always had.
$this->setParameter('breadcrumbs', array_values(array_filter([
    ['label' => 'Audio to Text', 'route' => AudioToTextRoute::PAGE],
    $store === null ? null : [
        'label' => $store->name,
        'route' => AudioToTextRoute::STORE,
        'arguments' => ['sourceId' => $store->sourceId],
    ],
    ['label' => 'Conversions', 'route' => AudioToTextRoute::JOBS],
    ['label' => 'Correct speakers'],
])));

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

// Read from the shared source rather than written out here, so the pencil on this page and the pencil
// in the store page's Details dialog cannot drift into two slightly different pencils.
$icon = AudioToTextIcons::svg(...);
$pencil = AudioToTextIcons::PENCIL;
$clock = AudioToTextIcons::CLOCK;
$grip = AudioToTextIcons::GRIP;
?>
<div class="a2t-chat a2t-review" data-a2t-review>
    <div class="a2t-chat__header">
        <div class="a2t-chat__heading">
            <h1 class="a2t-chat__title">Correct speakers</h1>
            <p class="a2t-chat__subtitle"><?= Html::encode($job->originalFilename) ?></p>
        </div>
        <div class="a2t-chat__actions">
            <?php if ($store !== null): ?>
                <?php
                // Added beside the existing actions rather than replacing one of them, so that hiding
                // or restoring any of them stays an independent decision — which is exactly what
                // happened to "Back to conversation" below.
                ?>
                <a class="btn" href="<?= Html::encode($urlGenerator->generate(
                    AudioToTextRoute::STORE,
                    ['sourceId' => $store->sourceId],
                )) ?>">Back to <?= Html::encode($store->name) ?></a>
            <?php endif; ?>
            <?php
            // Hidden by a stylesheet rule rather than removed, so bringing it back is deleting one CSS
            // line and nothing else — the route, the URL and this markup all stay working meanwhile.
            // See `.a2t-chat__action--conversation` in admin.css.
?>
            <a class="btn a2t-chat__action--conversation" href="<?= Html::encode($conversationUrl) ?>">Back to conversation</a>
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
            <?php if ($page->voice !== null): ?>
                <?php
                // The upload named the speaker, so there is no separation to stand behind and no
                // second party to assign anything to. Saying so is more useful than leaving the row
                // empty, which would read as a question nobody had got round to answering.
                ?>
                <span class="a2t-review__state a2t-review__state--confirmed">
                    This recording is the <strong><?= Html::encode($page->voice->label) ?></strong>
                    side of the call, so every message in it is theirs.
                </span>
            <?php elseif ($page->confirmedAt !== null): ?>
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
                <?php
                // data-a2t-confirm-form is the script's cue to remember which turn the reader was on
                // before the Post/Redirect/Get replaces this page. Only this form carries it: the other
                // corrections can reorder, split or merge turns, so a turn index saved before one of
                // them would not name the same turn on the page that comes back. Confirmation
                // re-serialises the same turns in the same order, which is what makes the index safe.
                ?>
                <form method="post" action="<?= Html::encode($pageUrl(AudioToTextRoute::JOB_REVIEW_CONFIRM)) ?>"
                      data-a2t-confirm-form>
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
                        <?php
                        // Display only, and ONLY while this is still the machine's own text — a saved
                        // correction is shown exactly as the administrator wrote it.
                        //
                        // `data-a2t-raw` carries the underlying value because admin.js reads this node
                        // when Cancel restores the editor. Without it the rendered price would be
                        // written back into the textarea and saved as a human correction on the next
                        // press of Save, which is precisely what must never happen.
                        $shown = SpokenPrice::formatUnlessReviewed($turn->text, $page->isReviewed);
                ?>
                        <span class="a2t-turn__text" data-a2t-text
                              data-a2t-raw="<?= Html::encode($turn->text) ?>"><?= Html::encode($shown) ?></span>
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
                            <?php if ($page->voice === null): ?>
                            <button class="a2t-iconbtn a2t-iconbtn--grip" type="button" data-a2t-grip
                                    title="Drag to move this message to the <?= Html::encode($other->label()) ?>"><?= $icon(
                                        $grip,
                                        'Drag to move this message to the ' . $other->label(),
                                    ) ?></button>
                            <?php endif; ?>
                            <button class="a2t-iconbtn" type="button" data-a2t-edit
                                    title="Correct the wording"><?= $icon($pencil, 'Correct the wording') ?></button>
                            <?php if ($turn->hasHistory()): ?>
                                <?php
                                // Only where there is something to show. "Something" means a correction
                                // that this message actually carries: confirming the roles changes no
                                // message, and everything before the last discard describes a
                                // conversation that no longer exists.
                                ?>
                                <button class="a2t-iconbtn" type="button"
                                        data-a2t-history="<?= $turn->index ?>"
                                        title="Show what was corrected"><?= $icon($clock, 'Show what was corrected') ?></button>
                            <?php endif; ?>
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
                        <?php if ($page->voice === null): ?>
                        <form method="post" action="<?= Html::encode($turnUrl(AudioToTextRoute::JOB_REVIEW_MOVE, $turn->index)) ?>">
                            <?= $csrfField ?><?= $version() ?>
                            <?= Html::hiddenInput('role', $other->value) ?>
                            <button class="btn btn--sm" type="submit">
                                Move to <?= Html::encode($other->label()) ?>
                            </button>
                        </form>
                        <?php endif; ?>

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
<?= $this->render(AudioToTextViews::reviewConfirm(), [
    'csrfField' => $csrfField,
    'version' => $page->version,
]) ?>

<?= $this->render(AudioToTextViews::reviewHistory(), [
    'turns' => $page->turns,
    'appTimeZone' => $appTimeZone,
    'voice' => $page->voice,
]) ?>
