<?php

declare(strict_types=1);

use App\Order58\Domain\SyncFreshness;
use App\Rules\Domain\RuleReadinessFilter;
use App\Rules\Domain\RuleReadinessResult;
use App\Rules\Domain\RuleReadinessSummary;
use App\Shared\Application\Time\AppTimeZone;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\Csrf;

/**
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var Csrf $csrf
 * @var RuleReadinessSummary $summary
 * @var RuleReadinessResult $result
 * @var string $search
 * @var RuleReadinessFilter $filter
 * @var int $page
 * @var SyncFreshness $freshness
 * @var AppTimeZone $appTimeZone
 */

$this->setTitle('Order58 Rules — Readiness');
$this->setParameter('breadcrumbs', [
    ['label' => 'Order58 Data Management', 'url' => $urlGenerator->generate('order58.index')],
    ['label' => 'Rules', 'url' => $urlGenerator->generate('order58.rules')],
    ['label' => 'Readiness'],
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

    return $urlGenerator->generate('order58.rules.readiness', [], $query);
};

$dash = static fn(?string $v): string => $v === null || $v === '' ? '—' : Html::encode($v);

$emptyMessage = match ($filter) {
    RuleReadinessFilter::Failed => 'No failed rules.',
    RuleReadinessFilter::Pending => 'No pending rules.',
    RuleReadinessFilter::Ready => 'No ready rules. Rule Chat stays unavailable until at least one rule is indexed.',
    RuleReadinessFilter::Disabled => 'No disabled or inactive rules.',
    RuleReadinessFilter::NotMaterialized => 'No rules waiting for materialization.',
    RuleReadinessFilter::All => 'No Order58 rules have been synced yet.',
};
if ($search !== '') {
    $emptyMessage = 'No rules match this search.';
}
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title">Rule readiness</h1>
        <p class="page-header__subtitle">
            Synced Order58 rules and their pipeline stage through global projection and OpenAI File Search.
            <strong>Synced does not mean Ready.</strong> A rule is <strong>Ready</strong> only with a live global
            projection and a completed, attached index file — that is what enables Rule Chat.
        </p>
    </div>

</div>

<?= $this->render(dirname(__DIR__) . '/_partial/rules-sync-banner', [
    'urlGenerator' => $urlGenerator,
    'csrf' => $csrf,
    'freshness' => $freshness,
    'appTimeZone' => $appTimeZone,
    'returnRoute' => 'order58.rules.readiness',
]) ?>

<section class="grid grid--stats">
    <a class="stat stat--link<?= $filter === RuleReadinessFilter::All ? ' stat--active' : '' ?>" href="<?= Html::encode($pageUrl(['filter' => 'all', 'page' => 1])) ?>">
        <div class="stat__value"><?= $summary->total() ?></div>
        <div class="stat__label">Synced</div>
    </a>
    <a class="stat stat--link<?= $filter === RuleReadinessFilter::Ready ? ' stat--active' : '' ?>" href="<?= Html::encode($pageUrl(['filter' => 'ready', 'page' => 1])) ?>">
        <div class="stat__value"><?= $summary->ready ?></div>
        <div class="stat__label">Ready</div>
    </a>
    <a class="stat stat--link<?= $filter === RuleReadinessFilter::Pending ? ' stat--active' : '' ?>" href="<?= Html::encode($pageUrl(['filter' => 'pending', 'page' => 1])) ?>">
        <div class="stat__value"><?= $summary->pending() ?></div>
        <div class="stat__label">Pending <span class="util-muted">· Q <?= $summary->queued ?> / P <?= $summary->processing ?> / I <?= $summary->indexing ?></span></div>
    </a>
    <a class="stat stat--link<?= $filter === RuleReadinessFilter::Failed ? ' stat--active' : '' ?>" href="<?= Html::encode($pageUrl(['filter' => 'failed', 'page' => 1])) ?>">
        <div class="stat__value"><?= $summary->failed ?></div>
        <div class="stat__label">Failed</div>
    </a>
    <a class="stat stat--link<?= $filter === RuleReadinessFilter::Disabled ? ' stat--active' : '' ?>" href="<?= Html::encode($pageUrl(['filter' => 'disabled', 'page' => 1])) ?>">
        <div class="stat__value"><?= $summary->disabledOrInactive() ?></div>
        <div class="stat__label">Disabled / Inactive</div>
    </a>
    <a class="stat stat--link<?= $filter === RuleReadinessFilter::NotMaterialized ? ' stat--active' : '' ?>" href="<?= Html::encode($pageUrl(['filter' => 'not_materialized', 'page' => 1])) ?>">
        <div class="stat__value"><?= $summary->notMaterialized ?></div>
        <div class="stat__label">Not materialized</div>
    </a>
</section>

<div class="dir-toolbar" style="margin-top: 1.25rem;">
    <form class="dir-search" method="get" action="<?= Html::encode($urlGenerator->generate('order58.rules.readiness')) ?>" role="search">
        <?php if ($filter !== RuleReadinessFilter::All): ?>
            <input type="hidden" name="filter" value="<?= Html::encode($filter->value) ?>">
        <?php endif; ?>
        <input class="field__control" type="search" name="q" value="<?= Html::encode($search) ?>"
            placeholder="Search rule, source id, canonical id or store…" aria-label="Search synced rules">
        <button class="btn btn--secondary" type="submit">Search</button>
        <?php if ($search !== ''): ?>
            <a class="btn btn--ghost" href="<?= Html::encode($pageUrl(['q' => '', 'page' => 1])) ?>">Clear</a>
        <?php endif; ?>
    </form>
</div>

<div class="filter-bar" role="group" aria-label="Filter by status">
    <?php foreach (RuleReadinessFilter::cases() as $case): ?>
        <a class="filter-chip<?= $case === $filter ? ' filter-chip--active' : '' ?>"
            href="<?= Html::encode($pageUrl(['filter' => $case->value, 'page' => 1])) ?>"><?= Html::encode($case->label()) ?></a>
    <?php endforeach; ?>
</div>

<section class="card">
    <div class="util-row" style="justify-content: space-between; align-items: baseline;">
        <h2 class="card__title" style="margin: 0;"><?= $result->total ?> synced rule<?= $result->total === 1 ? '' : 's' ?></h2>
        <span class="util-muted">Page <?= $page ?> of <?= $result->pageCount() ?></span>
    </div>

    <?php if ($result->items === []): ?>
        <p class="util-muted"><?= Html::encode($emptyMessage) ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Rule</th>
                        <th>Type</th>
                        <th>Store</th>
                        <th>Status</th>
                        <th>OpenAI file</th>
                        <th>Updated</th>
                        <th>Error</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($result->items as $item): ?>
                        <tr>
                            <td>
                                <span class="util-muted">src #<?= $item->sourceId ?></span>
                                <?php if ($item->canonicalId !== null): ?>
                                    · <a href="<?= Html::encode($urlGenerator->generate('order58.rules.detail', ['ruleId' => $item->canonicalId])) ?>"><strong>#<?= $item->canonicalId ?></strong></a>
                                <?php endif; ?>
                                <div><?= Html::encode($item->title) ?></div>
                            </td>
                            <td><?= Html::encode($item->typeLabel()) ?></td>
                            <td><?= $item->isStoreSpecific() ? $dash($item->storeName) : '—' ?></td>
                            <td><span class="badge badge--<?= Html::encode($item->status->badge()) ?>"><?= Html::encode($item->status->label()) ?></span></td>
                            <td><?php $fid = $item->shortFileId(); ?><?php if ($fid !== null): ?><code><?= Html::encode($fid) ?></code><?php else: ?><span class="util-muted">—</span><?php endif; ?></td>
                            <td class="util-muted"><?= Html::encode($item->updatedAt) ?></td>
                            <td>
                                <?php $err = $item->error ?? ''; ?>
                                <?php if ($err === ''): ?>
                                    <span class="util-muted">—</span>
                                <?php elseif (mb_strlen($err) <= 60): ?>
                                    <span class="util-muted"><?= Html::encode($err) ?></span>
                                <?php else: ?>
                                    <details>
                                        <summary class="util-muted"><?= Html::encode(mb_substr($err, 0, 60)) ?>…</summary>
                                        <div class="field__hint" style="white-space: pre-wrap; overflow-wrap: anywhere;"><?= Html::encode($err) ?></div>
                                    </details>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?= $this->render(dirname(__DIR__, 3) . '/Web/Shared/_partial/pager', [
            'page' => $page,
            'pageCount' => $result->pageCount(),
            'pageUrl' => static fn(int $p): string => $pageUrl(['page' => $p]),
        ]) ?>
    <?php endif; ?>
</section>
