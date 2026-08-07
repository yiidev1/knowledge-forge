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
    RuleReadinessFilter::Failed => 'No failed rule documents.',
    RuleReadinessFilter::Pending => 'No pending rule documents.',
    RuleReadinessFilter::Ready => 'No ready rule documents.',
    RuleReadinessFilter::Disabled => 'No disabled rule documents.',
    RuleReadinessFilter::All => 'No rule documents have been materialized yet.',
};
if ($search !== '') {
    $emptyMessage = 'No rule documents match this search.';
}
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title">Rule readiness</h1>
        <p class="page-header__subtitle">Materialized Order58 rule documents and their OpenAI File Search stage. A document is <strong>Ready</strong> only once it has a completed, attached index file.</p>
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
        <div class="stat__label">Total</div>
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
        <div class="stat__value"><?= $summary->disabled ?></div>
        <div class="stat__label">Disabled</div>
    </a>
</section>

<div class="dir-toolbar" style="margin-top: 1.25rem;">
    <form class="dir-search" method="get" action="<?= Html::encode($urlGenerator->generate('order58.rules.readiness')) ?>" role="search">
        <?php if ($filter !== RuleReadinessFilter::All): ?>
            <input type="hidden" name="filter" value="<?= Html::encode($filter->value) ?>">
        <?php endif; ?>
        <input class="field__control" type="search" name="q" value="<?= Html::encode($search) ?>"
            placeholder="Search rule, id or store…" aria-label="Search rule documents">
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
        <h2 class="card__title" style="margin: 0;"><?= $result->total ?> document<?= $result->total === 1 ? '' : 's' ?></h2>
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
                                <?php if ($item->canonicalId !== null): ?>
                                    <a href="<?= Html::encode($urlGenerator->generate('order58.rules.detail', ['ruleId' => $item->canonicalId])) ?>"><strong>#<?= $item->canonicalId ?></strong></a>
                                <?php endif; ?>
                                <?= Html::encode($item->title) ?>
                            </td>
                            <td><?= Html::encode($item->typeLabel()) ?></td>
                            <td><?= $item->isStoreSpecific ? $dash($item->storeName) : '—' ?></td>
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

        <?php if ($result->pageCount() > 1): ?>
            <div class="pager" style="margin-top: 1rem; display: flex; gap: .5rem; align-items: center;">
                <?php if ($page > 1): ?>
                    <a class="btn btn--secondary" href="<?= Html::encode($pageUrl(['page' => $page - 1])) ?>">← Previous</a>
                <?php endif; ?>
                <span class="util-muted">Page <?= $page ?> of <?= $result->pageCount() ?></span>
                <?php if ($page < $result->pageCount()): ?>
                    <a class="btn btn--secondary" href="<?= Html::encode($pageUrl(['page' => $page + 1])) ?>">Next →</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>