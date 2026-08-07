<?php

declare(strict_types=1);

use App\Order58\Domain\SyncFreshness;
use App\Rules\Domain\RuleReportFilter;
use App\Rules\Domain\RuleReportResult;
use App\Shared\Application\Time\AppTimeZone;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\Csrf;

/**
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var Csrf $csrf
 * @var RuleReportResult $result
 * @var string $search
 * @var RuleReportFilter $filter
 * @var int $page
 * @var SyncFreshness $freshness
 * @var AppTimeZone $appTimeZone
 */

$this->setTitle('Order58 Rules — Rule list');
$this->setParameter('breadcrumbs', [
    ['label' => 'Order58 Data Management', 'url' => $urlGenerator->generate('order58.index')],
    ['label' => 'Rule list'],
]);

$listUrl = static function (array $overrides) use ($urlGenerator, $search, $filter, $page): string {
    $query = ['q' => $search, 'filter' => $filter->value, 'page' => $page];
    foreach ($overrides as $key => $value) {
        $query[$key] = $value;
    }
    // Drop defaults to keep URLs clean.
    if (($query['q'] ?? '') === '') {
        unset($query['q']);
    }
    if (($query['filter'] ?? '') === RuleReportFilter::All->value) {
        unset($query['filter']);
    }
    if ((int) ($query['page'] ?? 1) <= 1) {
        unset($query['page']);
    }

    return $urlGenerator->generate('order58.rules.list', [], $query);
};

$dash = static fn(?string $v): string => $v === null || $v === '' ? '—' : Html::encode($v);
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title">Rule list</h1>
        <p class="page-header__subtitle">Every canonical Order58 rule with its classification, matched store, duplicate group size and searchable-document status.</p>
    </div>
    <div class="util-row">
        <a class="btn btn--secondary" href="<?= Html::encode($urlGenerator->generate('order58.rules.readiness')) ?>">Rule readiness</a>
        <a class="btn btn--secondary" href="<?= Html::encode($urlGenerator->generate('order58.rules')) ?>">← Summary</a>
    </div>
</div>

<?= $this->render(dirname(__DIR__) . '/_partial/rules-sync-banner', [
    'urlGenerator' => $urlGenerator,
    'csrf' => $csrf,
    'freshness' => $freshness,
    'appTimeZone' => $appTimeZone,
    'returnRoute' => 'order58.rules.list',
]) ?>

<div class="dir-toolbar">
    <form class="dir-search" method="get" action="<?= Html::encode($urlGenerator->generate('order58.rules.list')) ?>" role="search">
        <?php if ($filter !== RuleReportFilter::All): ?>
            <input type="hidden" name="filter" value="<?= Html::encode($filter->value) ?>">
        <?php endif; ?>
        <input class="field__control" type="search" name="q" value="<?= Html::encode($search) ?>"
               placeholder="Search rule title…" aria-label="Search rules">
        <button class="btn btn--secondary" type="submit">Search</button>
        <?php if ($search !== ''): ?>
            <a class="btn btn--ghost" href="<?= Html::encode($listUrl(['q' => '', 'page' => 1])) ?>">Clear</a>
        <?php endif; ?>
    </form>
</div>

<div class="filter-bar" role="group" aria-label="Filter rules">
    <?php foreach (RuleReportFilter::cases() as $case): ?>
        <a class="filter-chip<?= $case === $filter ? ' filter-chip--active' : '' ?>"
           href="<?= Html::encode($listUrl(['filter' => $case->value, 'page' => 1])) ?>"><?= Html::encode($case->label()) ?></a>
    <?php endforeach; ?>
</div>

<section class="card">
    <div class="util-row" style="justify-content: space-between; align-items: baseline;">
        <h2 class="card__title" style="margin: 0;"><?= $result->total ?> rule<?= $result->total === 1 ? '' : 's' ?></h2>
        <span class="util-muted">Page <?= $page ?> of <?= $result->pageCount() ?></span>
    </div>

    <?php if ($result->items === []): ?>
        <p class="util-muted">No rules match this filter.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr>
                    <th>Rule</th><th>Scope</th><th>Classification</th><th>Matched store</th>
                    <th>Dupes</th><th>Store doc</th><th>Global doc</th><th>Globally available</th><th></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($result->items as $item): ?>
                        <?php $detailUrl = $urlGenerator->generate('order58.rules.detail', ['ruleId' => $item->canonicalId]); ?>
                        <tr>
                            <td><a href="<?= Html::encode($detailUrl) ?>"><strong>#<?= $item->canonicalId ?></strong></a> <?= Html::encode($item->title) ?></td>
                            <td><?= Html::encode($item->scopeType) ?></td>
                            <td><?= Html::encode($item->classificationStatus) ?></td>
                            <td><?= $dash($item->matchedStoreName) ?></td>
                            <td><?= $item->duplicateGroupSize ?></td>
                            <td><?= $dash($item->storeDocumentStatus) ?></td>
                            <td><?= $dash($item->globalDocumentStatus) ?></td>
                            <td><span class="badge badge--<?= $item->isGloballyAvailable() ? 'ready' : 'muted' ?>"><?= $item->isGloballyAvailable() ? 'yes' : 'no' ?></span></td>
                            <td><a class="btn btn--secondary btn--sm" href="<?= Html::encode($detailUrl) ?>">Review</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="pager" style="margin-top: 1rem; display: flex; gap: .5rem; align-items: center;">
            <?php if ($page > 1): ?>
                <a class="btn btn--secondary" href="<?= Html::encode($listUrl(['page' => $page - 1])) ?>">← Previous</a>
            <?php endif; ?>
            <span class="util-muted">Page <?= $page ?> of <?= $result->pageCount() ?></span>
            <?php if ($page < $result->pageCount()): ?>
                <a class="btn btn--secondary" href="<?= Html::encode($listUrl(['page' => $page + 1])) ?>">Next →</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>
