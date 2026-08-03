<?php

declare(strict_types=1);

use App\Order58\Domain\StoreDirectoryFilter;
use App\Order58\Domain\StoreDirectoryItem;
use App\Order58\Domain\StoreDirectoryResult;
use App\Order58\Domain\StoreKnowledgeStatus;
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

/**
 * Build a directory URL from the current state with selective overrides. Resetting to page 1 on a
 * search/filter/letter change keeps pagination coherent.
 *
 * @param array<string, string|int> $overrides
 */
$dirUrl = static function (array $overrides) use ($base, $search, $filter, $letter, $page): string {
    $state = [
        'q' => $search,
        'filter' => $filter->value,
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

<div class="filter-bar" role="group" aria-label="Filter stores">
    <?php foreach (StoreDirectoryFilter::cases() as $option): ?>
        <?php $isActive = $option === $filter ? ' filter-chip--active' : ''; ?>
        <a class="filter-chip<?= $isActive ?>" href="<?= Html::encode($dirUrl(['filter' => $option->value, 'page' => 1])) ?>">
            <?= Html::encode($option->label()) ?>
        </a>
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
            $chatReady = $status === StoreKnowledgeStatus::Ready;
            ?>
            <article class="store-card<?= $chatReady ? ' store-card--has-chat' : '' ?>">
                <?php if ($chatReady): ?>
                    <a class="store-card__chat" href="<?= Html::encode($chatUrl) ?>" title="Open chat" aria-label="Open chat for <?= Html::encode($store->name) ?>">💬</a>
                <?php endif; ?>
                <div class="store-card__body">
                    <h2 class="store-card__name"><a href="<?= Html::encode($kbShowUrl) ?>"><?= Html::encode($store->name) ?></a></h2>
                    <?php if ($store->company !== null): ?>
                        <div class="store-card__meta"><?= Html::encode($store->company) ?></div>
                    <?php endif; ?>
                    <?php if ($location !== null): ?>
                        <div class="store-card__meta util-muted">📍 <?= Html::encode($location) ?></div>
                    <?php endif; ?>
                    <div class="store-card__meta util-mono util-muted">Store #<?= $store->sourceId ?></div>

                    <div class="store-card__badges">
                        <span class="badge badge--<?= Html::encode($status->badge()) ?>"><?= Html::encode($status->label()) ?></span>
                    </div>
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

    <?php if ($result->pageCount() > 1): ?>
        <nav class="pager" aria-label="Pagination">
            <?php if ($page > 1): ?>
                <a class="btn btn--secondary btn--sm" href="<?= Html::encode($dirUrl(['page' => $page - 1])) ?>">← Previous</a>
            <?php else: ?>
                <span class="btn btn--secondary btn--sm" aria-disabled="true" style="opacity:.5;">← Previous</span>
            <?php endif; ?>
            <span class="pager__status">Page <?= $page ?> of <?= $result->pageCount() ?></span>
            <?php if ($page < $result->pageCount()): ?>
                <a class="btn btn--secondary btn--sm" href="<?= Html::encode($dirUrl(['page' => $page + 1])) ?>">Next →</a>
            <?php else: ?>
                <span class="btn btn--secondary btn--sm" aria-disabled="true" style="opacity:.5;">Next →</span>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>
