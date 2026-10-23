<?php

declare(strict_types=1);

use App\OrderTesting\Domain\Practice\AttemptGroup;
use App\OrderTesting\Domain\Practice\PracticeTry;
use App\OrderTesting\Domain\TestAttempt;
use App\OrderTesting\Web\PracticeAttemptsAsset;
use App\Shared\Application\Time\AppTimeZone;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * Practice Attempts: one source order's history, grouped by who practised it.
 *
 * ## Summary first, comparison on request
 *
 * A card carries the score and nothing of the comparison itself. Rendering a side-by-side invoice
 * inside every card would put the original order — the answer — in front of a trainee who is about
 * to try again, and would draw a dozen invoices to show one number. **View Full Comparison** opens
 * the existing comparison page, unchanged, in a new tab.
 *
 * A perfect try is the exception: there is nothing left to practise, so the card says so plainly and
 * its link is the primary action rather than a quiet one.
 *
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var Yiisoft\Yii\View\Renderer\Csrf $csrf
 * @var Yiisoft\Assets\AssetManager $assetManager
 * @var AppTimeZone $appTimeZone
 * @var int $sourceId
 * @var string $storeName
 * @var int $sourceOrderId
 * @var list<AttemptGroup> $groups the groups to draw, after the Filter by agent choice
 * @var list<AttemptGroup> $allGroups every group this reader may see, for the filter's own options
 * @var ?string $chosenAgent the selected group key, or null for all of them
 * @var bool $canRetry whether this surface may start an attempt at all
 * @var bool $readerHasPractised whether the reader has an imported result of their own here
 * @var bool $sourceOrderIsMirrored
 * @var array<string, string> $routes the addresses this surface builds its links from
 */

$assetManager->register(PracticeAttemptsAsset::class);

$this->setTitle('Practice attempts for #' . $sourceOrderId);
$this->setParameter('breadcrumbs', [
    ['label' => $routes['pageLabel'], 'route' => $routes['page']],
    ['label' => $storeName, 'route' => $routes['store'], 'arguments' => ['sourceId' => $sourceId]],
    ['label' => 'Practice attempts'],
]);

$storeUrl = $urlGenerator->generate($routes['store'], ['sourceId' => $sourceId]);
$allDemoUrl = $urlGenerator->generate(
    $routes['allDemoOrders'],
    ['sourceId' => $sourceId, 'sourceOrderId' => $sourceOrderId],
);

/** The existing comparison page, for one demo order, on whichever surface this is. */
$compareUrl = static fn(int $demoOrderId): string => $urlGenerator->generate(
    $routes['demoCompare'],
    ['sourceId' => $sourceId, 'sourceOrderId' => $sourceOrderId, 'demoOrderId' => $demoOrderId],
);

$when = static fn(?DateTimeImmutable $at): string => $at === null
    ? '—'
    : $appTimeZone->format($at, 'M j, Y g:i A');

/** A stored UTC timestamp string, read in the business timezone like every other one on this page. */
$stamp = static fn(?string $utc): string => $utc === null || $utc === ''
    ? '—'
    : $appTimeZone->format(new DateTimeImmutable($utc . ' UTC'), 'M j, Y g:i A');

/**
 * One try, as a card.
 *
 * Three states, and they are genuinely different things rather than three colours of the same thing:
 * an attempt with a result, an attempt still waiting for one, and a demo order with no owner.
 */
/**
 * Who practised, as a sentence.
 *
 * The stored name when the account still exists, the realm and the id when it does not — an attempt
 * outlives the account that made it, and "AGENT #12" is a true statement where a blank is not.
 */
$testedBy = static function (?TestAttempt $attempt): string {
    if ($attempt === null) {
        return 'Unknown';
    }

    $name = $attempt->initiatedByName;

    return $attempt->initiatedByType->label() . ' '
        . ($name === null || $name === '' ? '#' . (string) $attempt->initiatedById : $name);
};

$card = static function (PracticeTry $try) use ($compareUrl, $when, $stamp, $testedBy): string {
    $outcome = $try->best();

    if ($try->isUnmatched()) {
        $demo = $outcome?->demo;
        $percent = $outcome?->score->displayPercent();

        $html = '<article class="pa-card pa-card--unmatched">'
            . '<header class="pa-card__head">'
            . '<span class="pa-card__try">Unmatched</span>'
            . '<span class="badge badge--muted">No attempt</span>'
            . '</header>'
            . '<p class="pa-card__demo">Demo #' . Html::encode((string) ($demo?->demoOrderId ?? 0)) . '</p>'
            . '<p class="pa-card__score pa-card__score--none">'
            . ($percent === null ? 'No score' : Html::encode((string) $percent) . '%')
            . '</p>'
            . '<dl class="pa-card__facts">'
            . '<dt>Tested by</dt><dd class="util-muted">Unknown</dd>'
            . '<dt>Imported</dt><dd>' . Html::encode($stamp($demo?->importedAt)) . '</dd>'
            . '</dl>'
            . '<p class="pa-card__note">Nobody could be shown to have created this order, so it is '
            . 'credited to no one.</p>';

        if ($demo !== null) {
            $html .= '<a class="btn btn--secondary btn--sm pa-card__action" target="_blank"'
                . ' rel="noopener noreferrer" href="' . Html::encode($compareUrl($demo->demoOrderId))
                . '">View Full Comparison</a>';
        }

        return $html . '</article>';
    }

    $attempt = $try->attempt;

    /*
     * There is no pending card any more.
     *
     * A try is only built where a demo order was actually imported — see `PracticeHistoryBuilder`.
     * An attempt with nothing behind it is still recorded and still auditable; it simply has nothing
     * to show yet, and a card saying so was six-sevenths of this page on the live store.
     */
    $demo = $outcome?->demo;
    $percent = $outcome?->score->displayPercent();
    $perfect = $outcome !== null && $outcome->isPerfect();
    $tone = $perfect ? 'perfect' : ($percent === null ? 'none' : ($percent >= 80 ? 'good' : 'low'));

    $html = '<article class="pa-card pa-card--' . ($perfect ? 'perfect' : 'scored') . '">'
        . '<header class="pa-card__head">'
        . '<span class="pa-card__try">Try ' . Html::encode((string) $try->number) . '</span>'
        . ($perfect
            ? '<span class="badge badge--success">Completed</span>'
            : '<span class="badge badge--muted">Imported</span>')
        . '</header>'
        . '<p class="pa-card__demo">Demo #' . Html::encode((string) ($demo?->demoOrderId ?? 0)) . '</p>'
        . '<p class="pa-card__score pa-card__score--' . $tone . '">'
        . ($percent === null ? 'No score' : Html::encode((string) $percent) . '%')
        . '</p>'
        . '<dl class="pa-card__facts">'
        . '<dt>Tested by</dt><dd>' . Html::encode($testedBy($attempt)) . '</dd>'
        . '<dt>Imported</dt><dd>' . Html::encode($stamp($demo?->importedAt)) . '</dd>'
        . '</dl>';

    // Every demo order of this attempt is kept. The card scores the best one and says so rather than
    // hiding the others, because "one click produced three orders" is a thing that happens.
    if ($try->demoCount() > 1) {
        $html .= '<p class="pa-card__note">'
            . Html::encode((string) $try->demoCount())
            . ' demo orders came from this attempt; the best is scored above.</p>';
    }

    if ($demo !== null) {
        $html .= '<a class="btn ' . ($perfect ? 'btn--primary' : 'btn--secondary')
            . ' btn--sm pa-card__action" target="_blank" rel="noopener noreferrer" href="'
            . Html::encode($compareUrl($demo->demoOrderId)) . '">View Full Comparison</a>';
    }

    return $html . '</article>';
};
?>

<div class="page-header">
    <div>
        <h1 class="page-header__title">Practice Attempts</h1>
        <p class="page-header__subtitle">
            <?= Html::encode($storeName) ?> · Store #<?= Html::encode((string) $sourceId) ?> —
            every attempt made on source order #<?= Html::encode((string) $sourceOrderId) ?>.
        </p>
    </div>
    <div class="page-header__actions">
        <?php if ($canRetry): ?>
            <?php
            /*
             * Retry goes through the ORDINARY Practice Order endpoint.
             *
             * A form posting to the same address the table's own button posts to, so the attempt is
             * created, the slot is taken and the concurrency rule is applied by exactly the code that
             * already does it. A link straight to Order58 would start an untracked attempt, which is
             * the one thing this must not do.
             */
            ?>
            <div class="pa-retry-wrap">
                <?php
                /*
                 * `target="_blank"`, on the form itself, for the path where the script is not there.
                 *
                 * The script below takes this submission over and opens the tab itself, so the
                 * attribute never applies when it loads. When it does not, the browser posts the form
                 * natively — and without this the endpoint's own page would replace the history the
                 * reader is looking at. The table's Practice Order button carries the same attribute
                 * for the same reason.
                 *
                 * `rel` is not decoration: the destination carries a customer's phone number in its
                 * path, and without `noreferrer` the browser hands this page's URL to Order58 as the
                 * Referer.
                 */
            ?>
                <form class="pa-retry" method="post" data-pa-retry
                      target="_blank" rel="noopener noreferrer"
                      action="<?= Html::encode($urlGenerator->generate($routes['demoUrl'], ['sourceId' => $sourceId])) ?>">
                    <?= $csrf->hiddenInput() ?>
                    <input type="hidden" name="source_order_id" value="<?= Html::encode((string) $sourceOrderId) ?>">
                    <?php
                    /*
                     * "Retry" only where the reader has finished this order before.
                     *
                     * The action is identical either way — the same tracked endpoint, the same
                     * attempt — so this is a label, not a permission. But calling a first attempt a
                     * retry is simply untrue, and an administrator reading somebody else's history
                     * has not practised it themselves.
                     */
            ?>
                    <button class="btn btn--primary" type="submit"><?=
                                $readerHasPractised ? 'Retry Practice' : 'Start Practice' ?></button>
                </form>
                <?php
                            // Where a refused or failed start is reported. The script fills this instead of
                            // navigating, so a refusal never looks like a successful start. With no script the
                            // form posts normally and the endpoint renders its own page — the path that has
                            // always existed.
            ?>
                <p class="pa-retry__error" data-pa-retry-error hidden></p>
            </div>
        <?php endif; ?>
        <a class="btn btn--secondary" href="<?= Html::encode($allDemoUrl) ?>">All Demo Orders</a>
        <a class="btn btn--ghost" href="<?= Html::encode($storeUrl) ?>">Back to Store</a>
    </div>
</div>

<?php if (!$sourceOrderIsMirrored): ?>
    <p class="pa-warning">
        This source order is not mirrored here, so comparisons have nothing to compare against.
        Sync this store's orders to fill the original side.
    </p>
<?php endif; ?>

<?php
                        /*
             * Filter by agent.
             *
             * Rendered only where there is more than one thing to choose between — a filter over a single group
             * is a control that cannot change anything. A GET form with an explicit Apply, like the topic filter
             * on the store page: no inline script, nothing navigates on change, and the choice survives a reload
             * and a bookmark because it lives in the URL.
             */
?>
<?php if (count($allGroups) > 1): ?>
    <form class="pa-filter" method="get" role="search">
        <label class="pa-filter__label" for="pa-agent">Filter by agent</label>
        <select class="field__control" id="pa-agent" name="agent">
            <option value=""<?= $chosenAgent === null ? ' selected' : '' ?>>All agents</option>
            <?php foreach ($allGroups as $option): ?>
                <option value="<?= Html::encode($option->key()) ?>"<?=
                $chosenAgent === $option->key() ? ' selected' : '' ?>>
                    <?= Html::encode($option->label()) ?>
                    (<?= Html::encode((string) $option->tryCount()) ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn--secondary btn--sm" type="submit">Apply</button>
        <?php if ($chosenAgent !== null): ?>
            <a class="btn btn--ghost btn--sm" href="<?= Html::encode($urlGenerator->generate(
                $routes['self'],
                ['sourceId' => $sourceId, 'sourceOrderId' => $sourceOrderId],
            )) ?>">Clear</a>
        <?php endif; ?>
    </form>
<?php endif; ?>

<?php if ($groups === []): ?>
    <div class="empty">
        <div class="empty__icon" aria-hidden="true">🎯</div>
        <div class="empty__title">No completed practice orders yet</div>
        <p>
            A try appears here once the order has been recreated in Order58 <em>and</em> imported with
            Sync demo orders. Starting an attempt on its own does not create one.
        </p>
    </div>
<?php else: ?>
    <?php foreach ($groups as $group): ?>
        <section class="card pa-group<?= $group->isUnmatched() ? ' pa-group--unmatched' : '' ?>"
                 data-pa-group="<?= Html::encode($group->key()) ?>">
            <header class="pa-group__head">
                <h2 class="pa-group__name"><?= Html::encode($group->label()) ?></h2>
                <p class="pa-group__meta">
                    <?php if ($group->isUnmatched()): ?>
                        <?= Html::encode((string) $group->tryCount()) ?>
                        demo order<?= $group->tryCount() === 1 ? '' : 's' ?> with no identified author
                    <?php else: ?>
                        <?= Html::encode((string) $group->tryCount()) ?>
                        <?= $group->tryCount() === 1 ? 'try' : 'tries' ?>
                        <?php $best = $group->bestPercent(); ?>
                        <?php if ($best !== null): ?>
                            · best <?= Html::encode((string) $best) ?>%
                        <?php endif; ?>
                    <?php endif; ?>
                </p>
            </header>
            <?php // Three to a row on a desktop, and fewer as the window narrows — see the stylesheet.?>
            <div class="pa-grid">
                <?php foreach ($group->tries as $try): ?>
                    <?= $card($try) ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
<?php endif; ?>
