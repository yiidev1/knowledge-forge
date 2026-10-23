<?php

declare(strict_types=1);

use App\OrderTesting\Application\Practice\PractitionerTry;
use App\OrderTesting\Domain\InitiatorType;
use App\Shared\Application\Time\AppTimeZone;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * One practitioner's tries, as both a drawer's contents and a page of its own.
 *
 * The drawer lifts `[data-pr-tries-content]` out of this response and drops it in; with no script
 * the browser simply renders the page. One template, so the two can never disagree about what a try
 * history is.
 *
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var AppTimeZone $appTimeZone
 * @var int $sourceId
 * @var string $storeName
 * @var int $sourceOrderId
 * @var InitiatorType $realm
 * @var int $actorId
 * @var list<PractitionerTry> $tries
 * @var int $totalTries
 * @var int $page
 * @var int $perPage
 * @var array<string, string> $routes
 */

$this->setTitle('Tries for #' . $sourceOrderId);
$this->setParameter('breadcrumbs', [
    ['label' => $routes['pageLabel'], 'route' => $routes['page']],
    ['label' => $storeName, 'route' => $routes['store'], 'arguments' => ['sourceId' => $sourceId]],
    [
        'label' => 'Practice results',
        'route' => $routes['demoOrders'],
        'arguments' => ['sourceId' => $sourceId, 'sourceOrderId' => $sourceOrderId],
    ],
    ['label' => 'Tries'],
]);

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

$best = null;

foreach ($tries as $try) {
    if ($try->score->isAvailable()
        && ($best === null || (float) $try->score->overall > (float) $best->score->overall)
    ) {
        $best = $try;
    }
}

$latest = $tries[0] ?? null;
$pages = (int) ceil(max(1, $totalTries) / $perPage);
?>
<div data-pr-tries-content>
    <?php if ($tries === []): ?>
        <p class="util-muted">No completed results for this practitioner on this order.</p>
    <?php else: ?>
        <?php
        // Best and latest for this window. Stated rather than left to be read off the rows, because
        // "how are they doing" is the question somebody opened this to answer.
        ?>
        <div class="pr-drawer__stats">
            <div>
                <span class="pr-summary__value pr-score--<?= Html::encode($best === null ? 'none' : $band($best->score)) ?>"><?=
                    Html::encode($best === null ? '—' : $percent($best->score)) ?></span>
                <span class="pr-summary__label">Best</span>
            </div>
            <div>
                <span class="pr-summary__value pr-score--<?= Html::encode($latest === null ? 'none' : $band($latest->score)) ?>"><?=
                    Html::encode($latest === null ? '—' : $percent($latest->score)) ?></span>
                <span class="pr-summary__label">Latest</span>
            </div>
            <div>
                <span class="pr-summary__value"><?= Html::encode((string) $totalTries) ?></span>
                <span class="pr-summary__label">Completed tries</span>
            </div>
        </div>

        <table class="table pr-table pr-table--tries">
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
                            // The existing comparison page, in a new tab, so the drawer and the
                            // table behind it stay exactly as they were.
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

        <?php if ($pages > 1): ?>
            <?php // Bounded here too: a practitioner with dozens of tries is paged, not poured out.?>
            <nav class="pr-pager" aria-label="Try pages">
                <?php if ($page > 1): ?>
                    <a class="btn btn--sm" data-pr-tries-page
                       href="<?= Html::encode($urlGenerator->generate($routes['self'], [
                           'sourceId' => $sourceId,
                           'sourceOrderId' => $sourceOrderId,
                           'realm' => $realm->value,
                           'actorId' => $actorId,
                       ], ['page' => $page - 1])) ?>">Previous</a>
                <?php endif; ?>
                <span class="pr-pager__at">Page <?= Html::encode((string) $page) ?>
                    of <?= Html::encode((string) $pages) ?></span>
                <?php if ($page < $pages): ?>
                    <a class="btn btn--sm" data-pr-tries-page
                       href="<?= Html::encode($urlGenerator->generate($routes['self'], [
                           'sourceId' => $sourceId,
                           'sourceOrderId' => $sourceOrderId,
                           'realm' => $realm->value,
                           'actorId' => $actorId,
                       ], ['page' => $page + 1])) ?>">Next</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
