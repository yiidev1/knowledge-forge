<?php

declare(strict_types=1);

use App\Order58\Domain\StoreAgentAvailabilityFilter;
use App\Order58\Domain\StoreDirectoryFilter;
use App\Order58\Domain\StoreDirectoryItem;
use App\Order58\Domain\StoreDirectoryResult;
use App\Order58\Domain\StoreSourceStatusFilter;
use App\Shared\Web\Support\AlphabetIndex;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\Csrf;

/**
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var Csrf $csrf
 * @var StoreDirectoryResult $result
 * @var string $search
 * @var StoreDirectoryFilter $filter
 * @var StoreSourceStatusFilter $sourceStatus
 * @var StoreAgentAvailabilityFilter $agent
 * @var string $letter
 * @var int $page
 */

$this->setTitle('Order58 stores');
$this->setParameter('breadcrumbs', [
    ['label' => 'Order58 Data Management', 'route' => 'order58.index'],
    ['label' => 'Stores'],
]);

$base = $urlGenerator->generate('order58.stores');
$csrfField = (string) $csrf->hiddenInput();

// Maps a raw ChatUnavailableReason code to a short, user-safe label (presentation only — the canonical rule
// lives in KnowledgeBaseChatEligibilitySql / ChatAvailabilityPolicy).
$chatReasonLabel = static fn(?string $code): string => match ($code) {
    'source_inactive' => 'Source inactive',
    'not_provisioned' => 'Vector store processing',
    'order58_not_ready', 'no_qualifying_document' => 'No ready knowledge',
    default => 'Not ready',
};

/**
 * Build a directory URL from the current state with selective overrides. Resetting to page 1 on a
 * search/filter/letter change keeps pagination coherent.
 *
 * @param array<string, string|int> $overrides
 */
$dirUrl = static function (array $overrides) use ($base, $search, $filter, $sourceStatus, $agent, $letter, $page): string {
    $state = [
        'q' => $search,
        'filter' => $filter->value,
        'status' => $sourceStatus->value,
        'agent' => $agent->value,
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
        <h1 class="page-header__title">Stores</h1>
        <p class="page-header__subtitle"><?= $result->countFor(AlphabetIndex::ALL) ?> stores match — mirrored from Order58, each mapped to one knowledge base.</p>
    </div>
    <a class="btn btn--secondary" href="<?= Html::encode($urlGenerator->generate('order58.index')) ?>">Data Management</a>
</div>

<div class="dir-toolbar">
    <form class="dir-search" method="get" action="<?= Html::encode($base) ?>" role="search">
        <input class="field__control" type="search" name="q" value="<?= Html::encode($search) ?>"
               placeholder="Search by name, company, city, address or store ID" aria-label="Search stores">
        <?php if ($filter !== StoreDirectoryFilter::All): ?>
            <input type="hidden" name="filter" value="<?= Html::encode($filter->value) ?>">
        <?php endif; ?>
        <button class="btn btn--secondary" type="submit">Search</button>
        <?php if ($search !== ''): ?>
            <a class="btn btn--ghost" href="<?= Html::encode($dirUrl(['q' => '', 'page' => 1])) ?>">Clear</a>
        <?php endif; ?>
    </form>
</div>

<div class="filter-bar" role="group" aria-label="Filter by knowledge status">
    <?php foreach (StoreDirectoryFilter::cases() as $option): ?>
        <?php $isActive = $option === $filter ? ' filter-chip--active' : ''; ?>
        <a class="filter-chip<?= $isActive ?>" href="<?= Html::encode($dirUrl(['filter' => $option->value, 'page' => 1])) ?>">
            <?= Html::encode($option->label()) ?>
        </a>
    <?php endforeach; ?>
</div>
<div class="filter-bar" role="group" aria-label="Filter by source status and agent access">
    <?php foreach (StoreSourceStatusFilter::cases() as $option): ?>
        <?php $isActive = $option === $sourceStatus ? ' filter-chip--active' : ''; ?>
        <a class="filter-chip<?= $isActive ?>" href="<?= Html::encode($dirUrl(['status' => $option->value, 'page' => 1])) ?>"><?= Html::encode($option->label()) ?></a>
    <?php endforeach; ?>
    <span class="filter-bar__sep" aria-hidden="true"></span>
    <?php foreach (StoreAgentAvailabilityFilter::cases() as $option): ?>
        <?php $isActive = $option === $agent ? ' filter-chip--active' : ''; ?>
        <a class="filter-chip<?= $isActive ?>" href="<?= Html::encode($dirUrl(['agent' => $option->value, 'page' => 1])) ?>"><?= Html::encode($option->label()) ?></a>
    <?php endforeach; ?>
</div>

<nav class="alpha-nav" aria-label="Browse by letter">
    <?php $allActive = $letter === AlphabetIndex::ALL ? ' alpha-nav__item--active' : ''; ?>
    <a class="alpha-nav__item<?= $allActive ?>" href="<?= Html::encode($dirUrl(['letter' => AlphabetIndex::ALL, 'page' => 1])) ?>">
        All <span class="alpha-nav__count"><?= $result->countFor(AlphabetIndex::ALL) ?></span>
    </a>
    <?php foreach (AlphabetIndex::letters() as $l): ?>
        <?php $count = $result->countFor($l); ?>
        <?php if ($count === 0): ?>
            <span class="alpha-nav__item alpha-nav__item--empty"><?= Html::encode($l) ?></span>
        <?php else: ?>
            <?php $isActive = $letter === $l ? ' alpha-nav__item--active' : ''; ?>
            <a class="alpha-nav__item<?= $isActive ?>" href="<?= Html::encode($dirUrl(['letter' => $l, 'page' => 1])) ?>"><?= Html::encode($l) ?></a>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>

<?php if ($result->items === []): ?>
    <div class="empty" style="padding: 2rem;">
        <div class="empty__title">No stores match</div>
        <p>Try a different letter, filter or search term.</p>
    </div>
<?php else: ?>
    <div class="store-grid">
        <?php foreach ($result->items as $store): ?>
            <?php
            /** @var StoreDirectoryItem $store */
            $status = $store->knowledgeStatus;
            $location = $store->locationLine();
            $kbShowUrl = $urlGenerator->generate('kb.show', ['slug' => $store->slug]);
            $chatUrl = $urlGenerator->generate('chat.index', ['slug' => $store->slug]);
            $agentAccessUrl = $urlGenerator->generate('order58.store.agent-access', ['storeId' => $store->sourceId]);
            ?>
            <article class="store-card<?= $store->chatEligible ? ' store-card--has-chat' : '' ?>">
                <?php if ($store->chatEligible): ?>
                    <a class="store-card__chat" href="<?= Html::encode($chatUrl) ?>" title="Open chat" aria-label="Open chat for <?= Html::encode($store->name) ?>">💬</a>
                <?php endif; ?>
                <div class="store-card__body">
                    <div class="store-card__head">
                        <h2 class="store-card__name"><a href="<?= Html::encode($kbShowUrl) ?>"><?= Html::encode($store->name) ?></a></h2>
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
                        <?php else: ?>
                            <span class="badge badge--success" title="Active in Order58">Source active</span>
                        <?php endif; ?>
                        <span class="badge badge--<?= Html::encode($status->badge()) ?>"><?= Html::encode($status->label()) ?></span>
                        <span class="badge badge--<?= $store->agentEnabled ? 'info' : 'muted' ?>"><?= $store->agentEnabled ? 'Agent enabled' : 'Agent disabled' ?></span>
                    </div>

                    <?php if (!$store->chatEligible): ?>
                        <p class="store-card__inactive-note">Chat unavailable — <?= Html::encode($chatReasonLabel($store->chatReason)) ?>.</p>
                    <?php endif; ?>
                </div>

                <div class="store-card__actions">
                    <form method="post" action="<?= Html::encode($agentAccessUrl) ?>">
                        <?= $csrfField ?>
                        <input type="hidden" name="enabled" value="<?= $store->agentEnabled ? '0' : '1' ?>">
                        <button class="btn btn--<?= $store->agentEnabled ? 'primary' : 'secondary' ?> btn--sm" type="submit"
                                title="<?= $store->agentEnabled ? 'Agents can use this store — click to disable' : 'Click to allow agents to use this store' ?>">
                            <?= $store->agentEnabled ? 'Agent enabled' : 'Enable agent' ?>
                        </button>
                    </form>
                    <a class="btn btn--secondary btn--sm" href="<?= Html::encode($kbShowUrl) ?>">Manage KB</a>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <?= $this->render(dirname(__DIR__, 3) . '/Web/Shared/_partial/pager', [
        'page' => $page,
        'pageCount' => $result->pageCount(),
        'pageUrl' => static fn(int $p): string => $dirUrl(['page' => $p]),
    ]) ?>
<?php endif; ?>
