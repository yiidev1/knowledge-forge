<?php

declare(strict_types=1);

use App\Rules\Domain\RuleReadinessBaseInfo;
use App\Rules\Domain\RuleReadinessFilter;
use App\Rules\Domain\RuleReadinessResult;
use App\Rules\Domain\RuleReadinessSummary;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var RuleReadinessBaseInfo|null $base
 * @var RuleReadinessSummary $summary
 * @var RuleReadinessResult $result
 * @var string $search
 * @var RuleReadinessFilter $filter
 * @var int $page
 */

$this->setTitle('Order58 Global Rules base (hidden)');
$this->setParameter('breadcrumbs', [
    ['label' => 'Order58 Data Management', 'url' => $urlGenerator->generate('order58.index')],
    ['label' => 'Rules', 'url' => $urlGenerator->generate('order58.rules')],
    ['label' => 'Global rules base'],
]);

$pageUrl = static function (array $overrides) use ($urlGenerator, $search, $filter, $page): string {
    $query = ['q' => $search, 'filter' => $filter->value, 'page' => $page];
    foreach ($overrides as $key => $value) {
        $query[$key] = $value;
    }
    if (($query['q'] ?? '') === '') {
        unset($query['q']);
    }
    if (($query['filter'] ?? '') === RuleReadinessFilter::All->value) {
        unset($query['filter']);
    }
    if ((int) ($query['page'] ?? 1) <= 1) {
        unset($query['page']);
    }

    return $urlGenerator->generate('order58.rules.global', [], $query);
};

$vsBadge = static fn(string $status): string => $status === 'ready' ? 'success' : ($status === 'failed' ? 'error' : 'warning');
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title">Global rules base <span class="badge badge--muted">hidden</span></h1>
        <p class="page-header__subtitle">The hidden knowledge base every store falls back to at chat stage 2. Excluded from all directories — this URL-only page is where its contents are inspected. Read-only.</p>
    </div>
    <div class="util-row">
        <a class="btn btn--secondary" href="<?= Html::encode($urlGenerator->generate('order58.rules.readiness')) ?>">← Readiness</a>
    </div>
</div>

<?php if ($base === null): ?>
    <section class="card"><p class="util-muted">The hidden Global Rules base has not been provisioned yet. It is created lazily the first time a rule becomes globally available.</p></section>
<?php else: ?>
    <section class="card">
        <div class="table-wrap">
            <table class="table">
                <tbody>
                    <tr><th>Name</th><td><?= Html::encode($base->name) ?></td></tr>
                    <tr><th>Slug</th><td><code><?= Html::encode($base->slug) ?></code></td></tr>
                    <tr><th>Vector store</th><td><span class="badge badge--<?= Html::encode($vsBadge($base->vectorStoreStatus)) ?>"><?= Html::encode($base->vectorStoreStatus) ?></span></td></tr>
                </tbody>
            </table>
        </div>
    </section>

    <section class="grid grid--stats">
        <a class="stat stat--link<?= $filter === RuleReadinessFilter::All ? ' stat--active' : '' ?>" href="<?= Html::encode($pageUrl(['filter' => 'all', 'page' => 1])) ?>"><div class="stat__value"><?= $summary->total() ?></div><div class="stat__label">Total</div></a>
        <a class="stat stat--link<?= $filter === RuleReadinessFilter::Ready ? ' stat--active' : '' ?>" href="<?= Html::encode($pageUrl(['filter' => 'ready', 'page' => 1])) ?>"><div class="stat__value"><?= $summary->ready ?></div><div class="stat__label">Ready</div></a>
        <a class="stat stat--link<?= $filter === RuleReadinessFilter::Pending ? ' stat--active' : '' ?>" href="<?= Html::encode($pageUrl(['filter' => 'pending', 'page' => 1])) ?>"><div class="stat__value"><?= $summary->pending() ?></div><div class="stat__label">Pending</div></a>
        <a class="stat stat--link<?= $filter === RuleReadinessFilter::Failed ? ' stat--active' : '' ?>" href="<?= Html::encode($pageUrl(['filter' => 'failed', 'page' => 1])) ?>"><div class="stat__value"><?= $summary->failed ?></div><div class="stat__label">Failed</div></a>
        <a class="stat stat--link<?= $filter === RuleReadinessFilter::Disabled ? ' stat--active' : '' ?>" href="<?= Html::encode($pageUrl(['filter' => 'disabled', 'page' => 1])) ?>"><div class="stat__value"><?= $summary->disabled ?></div><div class="stat__label">Disabled</div></a>
    </section>

    <div class="dir-toolbar" style="margin-top: 1.25rem;">
        <form class="dir-search" method="get" action="<?= Html::encode($urlGenerator->generate('order58.rules.global')) ?>" role="search">
            <?php if ($filter !== RuleReadinessFilter::All): ?>
                <input type="hidden" name="filter" value="<?= Html::encode($filter->value) ?>">
            <?php endif; ?>
            <input class="field__control" type="search" name="q" value="<?= Html::encode($search) ?>" placeholder="Search rule or id…" aria-label="Search global rules">
            <button class="btn btn--secondary" type="submit">Search</button>
            <?php if ($search !== ''): ?><a class="btn btn--ghost" href="<?= Html::encode($pageUrl(['q' => '', 'page' => 1])) ?>">Clear</a><?php endif; ?>
        </form>
    </div>

    <section class="card">
        <div class="util-row" style="justify-content: space-between; align-items: baseline;">
            <h2 class="card__title" style="margin: 0;"><?= $result->total ?> global rule document<?= $result->total === 1 ? '' : 's' ?></h2>
            <span class="util-muted">Page <?= $page ?> of <?= $result->pageCount() ?></span>
        </div>
        <?php if ($result->items === []): ?>
            <p class="util-muted"><?= $search !== '' ? 'No global rule documents match this search.' : 'No global rule documents yet.' ?></p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Rule</th><th>Status</th><th>OpenAI file</th><th>Updated</th></tr></thead>
                    <tbody>
                        <?php foreach ($result->items as $item): ?>
                            <tr>
                                <td>
                                    <?php if ($item->canonicalId !== null): ?><a href="<?= Html::encode($urlGenerator->generate('order58.rules.detail', ['ruleId' => $item->canonicalId])) ?>"><strong>#<?= $item->canonicalId ?></strong></a> <?php endif; ?>
                                    <?= Html::encode($item->title) ?>
                                </td>
                                <td><span class="badge badge--<?= Html::encode($item->status->badge()) ?>"><?= Html::encode($item->status->label()) ?></span></td>
                                <td><?php $fid = $item->shortFileId(); ?><?php if ($fid !== null): ?><code><?= Html::encode($fid) ?></code><?php else: ?><span class="util-muted">—</span><?php endif; ?></td>
                                <td class="util-muted"><?= Html::encode($item->updatedAt) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($result->pageCount() > 1): ?>
                <div class="pager" style="margin-top: 1rem; display: flex; gap: .5rem; align-items: center;">
                    <?php if ($page > 1): ?><a class="btn btn--secondary" href="<?= Html::encode($pageUrl(['page' => $page - 1])) ?>">← Previous</a><?php endif; ?>
                    <span class="util-muted">Page <?= $page ?> of <?= $result->pageCount() ?></span>
                    <?php if ($page < $result->pageCount()): ?><a class="btn btn--secondary" href="<?= Html::encode($pageUrl(['page' => $page + 1])) ?>">Next →</a><?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
<?php endif; ?>
