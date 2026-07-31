<?php

declare(strict_types=1);

use App\Order58\Domain\StoreDirectoryItem;
use App\Order58\Domain\StoreDirectoryResult;
use App\Shared\Web\Support\AlphabetIndex;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var StoreDirectoryResult $result
 * @var string $search
 * @var string $letter
 * @var int $page
 */

$this->setTitle('Store chat');
$this->setParameter('breadcrumbs', [
    ['label' => 'Order58 Data Management', 'route' => 'order58.index'],
    ['label' => 'Store chat'],
]);

$base = $urlGenerator->generate('order58.store-chat');

/**
 * @param array<string, string|int> $overrides
 */
$dirUrl = static function (array $overrides) use ($base, $search, $letter, $page): string {
    $state = ['q' => $search, 'letter' => $letter, 'page' => $page];
    foreach ($overrides as $key => $value) {
        $state[$key] = (string) $value;
    }
    $params = [];
    if ($state['q'] !== '') {
        $params['q'] = $state['q'];
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
            ?>
            <a class="store-card store-card--link" href="<?= Html::encode($chatUrl) ?>">
                <div class="store-card__body">
                    <h2 class="store-card__name"><?= Html::encode($store->name) ?></h2>
                    <?php if ($store->company !== null): ?>
                        <div class="store-card__meta"><?= Html::encode($store->company) ?></div>
                    <?php endif; ?>
                    <?php if ($location !== null): ?>
                        <div class="store-card__meta util-muted">📍 <?= Html::encode($location) ?></div>
                    <?php endif; ?>
                    <div class="store-card__meta util-mono util-muted">Store #<?= $store->sourceId ?></div>
                </div>
                <div class="store-card__footer">
                    <span class="btn btn--primary btn--block">Open chat</span>
                </div>
            </a>
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
