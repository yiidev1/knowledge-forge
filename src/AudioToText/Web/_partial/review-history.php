<?php

declare(strict_types=1);

use App\AudioToText\Domain\Speaker\ReviewedTurn;
use App\AudioToText\Domain\Speaker\TranscriptVoice;
use App\AudioToText\Web\Job\Review\ReviewTurnView;
use App\Shared\Application\Time\AppTimeZone;
use Yiisoft\Html\Html;

/**
 * What was corrected, one dialog per message that has anything to show.
 *
 * Shared by the correction page, which renders it inline, and the store page's Details dialog, which
 * fetches it from {@see \App\AudioToText\Web\Job\Review\History\Action}. One rendering, so a
 * revision reads the same wherever it is opened — and one set of escaping.
 *
 * Every historical word goes through {@see Html::encode} on the way in: a transcript is data, and a
 * transcript from six revisions ago is no more trustworthy than today's.
 *
 * @var Yiisoft\View\WebView $this
 * @var list<ReviewTurnView> $turns
 * @var AppTimeZone $appTimeZone
 * @var TranscriptVoice|null $voice the speaker the upload named, when it named one
 */

$voice ??= null;

$clock2 = static fn(int $ms): string => sprintf('%02d:%02d', intdiv($ms, 60000), intdiv($ms % 60000, 1000));

$historyTurn = static function (ReviewedTurn $t) use ($clock2, $voice): string {
    // The span as stored. A turn a person split carries its parent's range and says so with the tilde
    // the rest of the feature already uses, rather than a number nobody measured.
    $range = $t->startMs === 0 && $t->endMs === 0
        ? ''
        : ' · ' . ($t->approx ? '~' : '') . $clock2($t->startMs) . '–' . $clock2($t->endMs);

    // The stored role describes a hypothesis the diarizer made inside this file. Where the upload
    // named the speaker there is no such question, so the revision is labelled with the answer that
    // was given rather than the one that was guessed.
    return '<p class="a2t-history__turn"><span class="a2t-history__who">'
        . Html::encode(($voice?->label ?? $t->role->label()) . $range)
        . '</span><span class="a2t-history__text">' . Html::encode($t->text) . '</span></p>';
};
?>
<?php foreach ($turns as $turn): ?>
    <?php if (!$turn->hasHistory()): ?>
        <?php continue; ?>
    <?php endif; ?>
    <dialog class="a2t-confirm a2t-history" data-a2t-history-dialog="<?= $turn->index ?>">
        <h2 class="a2t-confirm__title">What was corrected</h2>
        <?php foreach ($turn->history->events as $event): ?>
            <div class="a2t-history__event">
                <p class="a2t-history__head">
                    <strong><?= Html::encode($event->summary()) ?></strong>
                    <span class="a2t-history__meta">
                        revision <?= $event->revisionNumber ?>
                        · by <?= Html::encode($event->editedByUsername ?? 'an administrator') ?>
                        · <?= Html::encode($appTimeZone->format($event->createdAt, 'M j, Y g:i A T')) ?>
                    </span>
                </p>

                <?php
                // Both sides always, and both may hold more than one message. A merge that showed only
                // one "before" would read exactly like a text edit and misdescribe what was done.
            ?>
                <p class="a2t-history__label">Before</p>
                <?php foreach ($event->before as $t): ?>
                    <?= $historyTurn($t) ?>
                <?php endforeach; ?>

                <p class="a2t-history__label">After</p>
                <?php foreach ($event->after as $t): ?>
                    <?= $historyTurn($t) ?>
                <?php endforeach; ?>

                <?php if ($event->involvesSeveralTurns()): ?>
                    <p class="a2t-confirm__note">
                        This correction involved more than one message, so it is shown on each of them.
                    </p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <div class="a2t-confirm__actions">
            <button class="btn btn--sm" type="button" data-a2t-history-close>Close</button>
        </div>
    </dialog>
<?php endforeach; ?>
