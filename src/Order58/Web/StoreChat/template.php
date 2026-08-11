<?php

declare(strict_types=1);

use App\Order58\Domain\StoreAgentAvailabilityFilter;
use App\Order58\Domain\StoreChatAvailabilityFilter;
use App\Order58\Domain\StoreDirectoryFilter;
use App\Order58\Domain\StoreDirectoryItem;
use App\Order58\Domain\StoreDirectoryResult;
use App\Order58\Domain\StoreSourceStatusFilter;
use App\Shared\Web\Support\AlphabetIndex;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var StoreDirectoryResult $result
 * @var string $search
 * @var StoreDirectoryFilter $filter
 * @var StoreSourceStatusFilter $sourceStatus
 * @var StoreAgentAvailabilityFilter $agent
 * @var StoreChatAvailabilityFilter $chatAvailability
 * @var string $letter
 * @var int $page
 */

$this->setTitle('Store chat');
$this->setParameter('breadcrumbs', [
    ['label' => 'Order58 Data Management', 'route' => 'order58.index'],
    ['label' => 'Store chat'],
]);

$base = $urlGenerator->generate('order58.store-chat');

$chatReasonLabel = static fn(?string $code): string => match ($code) {
    'source_inactive' => 'Source inactive',
    'not_provisioned' => 'Vector store processing',
    'order58_not_ready', 'no_qualifying_document' => 'No ready knowledge',
    default => 'Not ready',
};

/**
 * @param array<string, string|int> $overrides
 */
$dirUrl = static function (array $overrides) use ($base, $search, $filter, $sourceStatus, $agent, $chatAvailability, $letter, $page): string {
    $state = [
        'q' => $search,
        'filter' => $filter->value,
        'status' => $sourceStatus->value,
        'agent' => $agent->value,
        'chat' => $chatAvailability->value,
        'letter' => $letter,
        'page' => $page,
    ];
    foreach ($overrides as $key => $value) {
        $state[$key] = (string) $value;
    }
    $params = [];
    if ($state['q'] !== '') {
        $params['q'] = $state['q'];
    }
    if ($state['filter'] !== StoreDirectoryFilter::All->value) {
        $params['filter'] = $state['filter'];
    }
    if ($state['status'] !== StoreSourceStatusFilter::All->value) {
        $params['status'] = $state['status'];
    }
    if ($state['agent'] !== StoreAgentAvailabilityFilter::All->value) {
        $params['agent'] = $state['agent'];
    }
    if ($state['chat'] !== StoreChatAvailabilityFilter::All->value) {
        $params['chat'] = $state['chat'];
    }
    if ($state['letter'] !== AlphabetIndex::ALL) {
        $params['letter'] = $state['letter'];
    }
    if ((int) $state['page'] > 1) {
        $params['page'] = (string) $state['page'];
    }

    return $params === [] ? $base : $base . '?' . http_build_query($params);
};
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title">Store chat</h1>
        <p class="page-header__subtitle">Pick a store to chat with its knowledge base — answers come only from that store's documents, with sources.</p>
    </div>
</div>

<div class="dir-toolbar">
    <form class="dir-search" method="get" action="<?= Html::encode($base) ?>" role="search">
        <input class="field__control" type="search" name="q" value="<?= Html::encode($search) ?>"
               placeholder="Search stores by name, company, city or address" aria-label="Search stores">
        <button class="btn btn--secondary" type="submit">Search</button>
        <?php if ($search !== ''): ?>
            <a class="btn btn--ghost" href="<?= Html::encode($dirUrl(['q' => '', 'page' => 1])) ?>">Clear</a>
        <?php endif; ?>
    </form>
</div>

<div class="filter-bar" role="group" aria-label="Filter by knowledge status">
    <?php foreach (StoreDirectoryFilter::cases() as $option): ?>
        <a class="filter-chip<?= $option === $filter ? ' filter-chip--active' : '' ?>" href="<?= Html::encode($dirUrl(['filter' => $option->value, 'page' => 1])) ?>"><?= Html::encode($option->label()) ?></a>
    <?php endforeach; ?>
    <span class="filter-bar__sep" aria-hidden="true"></span>
    <?php foreach (StoreSourceStatusFilter::cases() as $option): ?>
        <a class="filter-chip<?= $option === $sourceStatus ? ' filter-chip--active' : '' ?>" href="<?= Html::encode($dirUrl(['status' => $option->value, 'page' => 1])) ?>"><?= Html::encode($option->label()) ?></a>
    <?php endforeach; ?>
    <span class="filter-bar__sep" aria-hidden="true"></span>
    <?php foreach (StoreChatAvailabilityFilter::cases() as $option): ?>
        <?php if ($option === StoreChatAvailabilityFilter::Unavailable): continue; endif; // the picker is for opening chat, so a "Chat unavailable" filter is not useful here?>
        <a class="filter-chip<?= $option === $chatAvailability ? ' filter-chip--active' : '' ?>" href="<?= Html::encode($dirUrl(['chat' => $option->value, 'page' => 1])) ?>"><?= Html::encode($option->label()) ?></a>
    <?php endforeach; ?>
</div>

<nav class="alpha-nav" aria-label="Browse by letter">
    <?php $allActive = $letter === AlphabetIndex::ALL ? ' alpha-nav__item--active' : ''; ?>
    <a class="alpha-nav__item<?= $allActive ?>" href="<?= Html::encode($dirUrl(['letter' => AlphabetIndex::ALL, 'page' => 1])) ?>">
        All <span class="alpha-nav__count"><?= $result->countFor(AlphabetIndex::ALL) ?></span>
    </a>
    <?php foreach (AlphabetIndex::letters() as $l): ?>
        <?php if ($result->countFor($l) === 0): ?>
            <span class="alpha-nav__item alpha-nav__item--empty"><?= Html::encode($l) ?></span>
        <?php else: ?>
            <?php $isActive = $letter === $l ? ' alpha-nav__item--active' : ''; ?>
            <a class="alpha-nav__item<?= $isActive ?>" href="<?= Html::encode($dirUrl(['letter' => $l, 'page' => 1])) ?>"><?= Html::encode($l) ?></a>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>

<?php if ($result->items === []): ?>
    <div class="empty" style="padding: 2rem;">
        <div class="empty__icon" aria-hidden="true">💬</div>
        <div class="empty__title">No stores match</div>
        <p>Try a different letter or search term.</p>
    </div>
<?php else: ?>
    <div class="store-grid">
        <?php foreach ($result->items as $store): ?>
            <?php
            /** @var StoreDirectoryItem $store */
            $chatUrl = $urlGenerator->generate('chat.index', ['slug' => $store->slug]);
            $location = $store->locationLine();
            $eligible = $store->chatEligible;
            // Chat-eligible stores are the usual link card; ineligible ones are non-clickable with the reason.
            $tag = $eligible ? 'a' : 'div';
            $attrs = $eligible
                ? 'class="store-card store-card--link" href="' . Html::encode($chatUrl) . '"'
                : 'class="store-card" aria-disabled="true"';
            ?>
            <<?= $tag ?> <?= $attrs ?>>
                <div class="store-card__body">
                    <div class="store-card__head">
                        <h2 class="store-card__name"><?= Html::encode($store->name) ?></h2>
                        <?php $countOk = $store->knowledgeDocsTotal > 0 && $store->knowledgeDocsIndexed === $store->knowledgeDocsTotal; ?>
                        <span class="store-card__kb-count<?= $countOk ? ' store-card__kb-count--ok' : '' ?>" title="<?= $store->knowledgeRecordCount ?> knowledge records · <?= $store->knowledgeDocsIndexed ?>/<?= $store->knowledgeDocsTotal ?> documents indexed"><?= $store->knowledgeRecordCount ?> · <?= $store->knowledgeDocsIndexed ?>/<?= $store->knowledgeDocsTotal ?></span>
                    </div>
                    <?php if ($store->company !== null): ?>
                        <div class="store-card__meta"><?= Html::encode($store->company) ?></div>
                    <?php endif; ?>
                    <?php if ($location !== null): ?>
                        <div class="store-card__meta util-muted">📍 <?= Html::encode($location) ?></div>
                    <?php endif; ?>
                    <div class="store-card__meta util-mono util-muted">Store #<?= $store->sourceId ?></div>
                    <div class="store-card__badges">
                        <?php if (!$store->sourceActive): ?>
                            <span class="badge badge--error" title="Order58 reports this store as inactive">🔴 Source inactive</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="store-card__footer">
                    <?php if ($eligible): ?>
                        <span class="btn btn--primary btn--block">Open chat</span>
                    <?php else: ?>
                        <span class="btn btn--primary btn--block" aria-disabled="true">Chat unavailable — <?= Html::encode($chatReasonLabel($store->chatReason)) ?></span>
                    <?php endif; ?>
                </div>
            </<?= $tag ?>>
        <?php endforeach; ?>
    </div>

    <?= $this->render(dirname(__DIR__, 3) . '/Web/Shared/_partial/pager', [
        'page' => $page,
        'pageCount' => $result->pageCount(),
        'pageUrl' => static fn(int $p): string => $dirUrl(['page' => $p]),
    ]) ?>
<?php endif; ?>
