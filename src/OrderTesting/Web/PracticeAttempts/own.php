<?php

declare(strict_types=1);

use App\OrderTesting\Application\Practice\PractitionerTry;
use App\OrderTesting\Web\PracticeAttemptsAsset;
use App\Shared\Application\Time\AppTimeZone;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * One practitioner's own practice history — the agent's page.
 *
 * ## Deliberately not the management view
 *
 * An agent has one practitioner to look at: themselves. So there is no table of practitioners, no
 * search, no pagination over people and no drawer — their tries are the page, visible immediately,
 * because clicking to reveal your own four results is a click that buys nothing.
 *
 * Nor are the management counts here. How many colleagues practised this order, and how many
 * results exist in total, are facts about other people; this surface never receives them, which is
 * a stronger guarantee than not drawing them.
 *
 * ## Authorization is upstream of this file
 *
 * The rows were fetched already narrowed to this agent, by realm **and** id, in the query. A
 * template cannot leak what was never selected — which is why the narrowing is there and not here.
 *
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var Yiisoft\Yii\View\Renderer\Csrf $csrf
 * @var Yiisoft\Assets\AssetManager $assetManager
 * @var AppTimeZone $appTimeZone
 * @var int $sourceId
 * @var string $storeName
 * @var int $sourceOrderId
 * @var list<PractitionerTry> $tries newest first
 * @var int $totalTries
 * @var ?string $topic
 * @var bool $canRetry
 * @var bool $readerHasPractised
 * @var bool $sourceOrderIsMirrored
 * @var array<string, string> $routes
 */

$assetManager->register(PracticeAttemptsAsset::class);

$this->setTitle('Practice history for #' . $sourceOrderId);
$this->setParameter('breadcrumbs', [
    ['label' => $routes['pageLabel'], 'route' => $routes['page']],
    ['label' => $storeName, 'route' => $routes['store'], 'arguments' => ['sourceId' => $sourceId]],
    ['label' => 'Practice history'],
]);

$storeUrl = $urlGenerator->generate($routes['store'], ['sourceId' => $sourceId]);

/** A score, or a dash. Never 0% for a comparison that could not be made — see the table template. */
$percent = static function (App\OrderTesting\Domain\Comparison\ComparisonScore $score): string {
    return $score->isAvailable() ? (string) $score->displayPercent() . '%' : '—';
};

$band = static function (App\OrderTesting\Domain\Comparison\ComparisonScore $score): string {
    if (!$score->isAvailable()) {
        return 'none';
    }

    $value = (int) $score->displayPercent();

    return match (true) {
        $value >= 90 => 'excellent',
        $value >= 75 => 'good',
        $value >= 50 => 'fair',
        default => 'poor',
    };
};

/*
 * Best and latest, from the rows in hand.
 *
 * Taken from this page rather than asked for separately: an agent's own history is bounded by the
 * same page size as everybody's, and for the handful of results an agent has it is all of them. A
 * score that could not be computed is skipped rather than counted as zero.
 */
$best = null;
$latest = $tries[0] ?? null;

foreach ($tries as $try) {
    if ($try->score->isAvailable()
        && ($best === null || (float) $try->score->overall > (float) $best->score->overall)
    ) {
        $best = $try;
    }
}
?>

<div class="page-header">
    <div>
        <h1 class="page-header__title">Practice History</h1>
        <p class="page-header__subtitle">
            <?= Html::encode($storeName) ?> &mdash; source order
            #<?= Html::encode((string) $sourceOrderId) ?>.
            <?php if ($topic !== null): ?>
                <span class="pr-topic">Topic: <?= Html::encode($topic) ?></span>
            <?php endif; ?>
        </p>
    </div>
    <div class="page-header__actions">
        <?php if ($canRetry): ?>
            <div class="pa-retry-wrap">
                <form class="pa-retry" method="post" data-pa-retry
                      target="_blank" rel="noopener noreferrer"
                      action="<?= Html::encode($urlGenerator->generate($routes['demoUrl'], ['sourceId' => $sourceId])) ?>">
                    <?= $csrf->hiddenInput() ?>
                    <input type="hidden" name="source_order_id" value="<?= Html::encode((string) $sourceOrderId) ?>">
                    <?php
                    // "Retry" only once THIS agent has an imported result. Never because a colleague
                    // or an administrator has one — that is somebody else's practice.
            ?>
                    <button class="btn btn--primary" type="submit"><?=
                $readerHasPractised ? 'Retry Practice' : 'Start Practice' ?></button>
                </form>
                <p class="pa-retry__error" data-pa-retry-error hidden></p>
            </div>
        <?php endif; ?>
        <a class="btn btn--ghost" href="<?= Html::encode($storeUrl) ?>">Back to Store</a>
    </div>
</div>

<?php if (!$sourceOrderIsMirrored): ?>
    <p class="pa-warning">
        This source order is not mirrored here, so comparisons have nothing to compare against.
    </p>
<?php endif; ?>

<?php if ($tries === []): ?>
    <div class="card a2t-wide">
        <div class="a2t-empty-state">
            <?php
            // The wording the client asked for, and no Pending card beside it: pressing Practice
            // Order records an attempt, and an attempt that produced nothing is not a result.
    ?>
            <p>No completed practice orders yet.</p>
            <p class="util-muted">
                Press Start Practice to recreate this order in Order58. Your result appears here once
                it has been imported.
            </p>
        </div>
    </div>
<?php else: ?>
    <div class="pr-summary pr-summary--own">
        <div class="pr-summary__item">
            <span class="pr-summary__value pr-score--<?= Html::encode($best === null ? 'none' : $band($best->score)) ?>"><?=
        Html::encode($best === null ? '—' : $percent($best->score)) ?></span>
            <span class="pr-summary__label">Best score</span>
        </div>
        <div class="pr-summary__item">
            <span class="pr-summary__value pr-score--<?= Html::encode($latest === null ? 'none' : $band($latest->score)) ?>"><?=
        Html::encode($latest === null ? '—' : $percent($latest->score)) ?></span>
            <span class="pr-summary__label">Latest score</span>
        </div>
        <div class="pr-summary__item">
            <span class="pr-summary__value"><?= Html::encode((string) $totalTries) ?></span>
            <span class="pr-summary__label">Completed tries</span>
        </div>
    </div>

    <div class="card a2t-wide">
        <h2 class="card__title">My tries</h2>
        <div class="a2t-table-scroll">
            <table class="table pr-table">
                <thead>
                    <tr>
                        <th>Try</th>
                        <th>Demo order</th>
                        <th class="pr-num">Score</th>
                        <th>Imported</th>
                        <th><span class="util-visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tries as $try): ?>
                        <tr>
                            <td data-ot-label="Try">Try <?= Html::encode((string) $try->number) ?></td>
                            <td data-ot-label="Demo order">#<?= Html::encode((string) $try->demo->demoOrderId) ?></td>
                            <td data-ot-label="Score" class="pr-num">
                                <span class="pr-score pr-score--<?= Html::encode($band($try->score)) ?>"><?=
                            Html::encode($percent($try->score)) ?></span>
                            </td>
                            <td data-ot-label="Imported"><?= $try->demo->importedAt === null
                        ? '<span class="util-muted">&mdash;</span>'
                        : Html::encode($try->demo->importedAt) ?></td>
                            <td data-ot-label="Actions" class="pr-actions">
                                <?php
                        // The existing comparison page, at this surface's own address, in a
                        // new tab — so a trainee comparing their work does not lose this list.
                        ?>
                                <a class="ot-rowaction" target="_blank" rel="noopener noreferrer"
                                   href="<?= Html::encode($urlGenerator->generate($routes['demoCompare'], [
                               'sourceId' => $sourceId,
                               'sourceOrderId' => $sourceOrderId,
                               'demoOrderId' => $try->demo->demoOrderId,
                           ])) ?>">View Comparison</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalTries > count($tries)): ?>
            <p class="util-muted">
                Showing your <?= Html::encode((string) count($tries)) ?> most recent of
                <?= Html::encode((string) $totalTries) ?>.
            </p>
        <?php endif; ?>
    </div>
<?php endif; ?>
