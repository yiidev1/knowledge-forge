<?php

declare(strict_types=1);

use App\Agent\Domain\AgentStore;
use App\Shared\Web\Support\AlphabetIndex;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var list<AgentStore> $stores
 * @var array<string, int> $counts
 * @var string $letter
 * @var string $search
 * @var int $page
 * @var int $pageCount
 * @var int $totalMatched
 * @var int $totalAvailable
 */

$this->setTitle('Stores');

$base = $urlGenerator->generate('agent.home');

// A directory URL that keeps the current search while switching letter (or vice-versa); resets to page 1.
$dirUrl = static function (string $forLetter, string $forSearch) use ($base): string {
    $params = array_filter([
        'letter' => $forLetter === AlphabetIndex::ALL ? '' : $forLetter,
        'q' => $forSearch,
    ], static fn(string $v): bool => $v !== '');

    return $params === [] ? $base : $base . '?' . http_build_query($params);
};

// A pager URL that keeps letter + search while changing page.
$pageUrl = static function (int $forPage) use ($base, $letter, $search): string {
    $params = array_filter([
        'letter' => $letter === AlphabetIndex::ALL ? '' : $letter,
        'q' => $search,
        'page' => $forPage > 1 ? (string) $forPage : '',
    ], static fn(string $v): bool => $v !== '');

    return $params === [] ? $base : $base . '?' . http_build_query($params);
};
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title">Stores</h1>
        <p class="page-header__subtitle">Choose a store to chat with its knowledge base. Answers come only from that store's documents, with sources.</p>
    </div>
</div>

<?php if ($totalAvailable === 0): ?>
    <div class="empty">
        <div class="empty__icon" aria-hidden="true">🏬</div>
        <div class="empty__title">No stores available yet</div>
        <p>No store is ready for chat right now. Please check back shortly.</p>
    </div>
<?php else: ?>
    <div class="dir-toolbar">
        <form class="dir-search" method="get" action="<?= Html::encode($base) ?>" role="search">
            <input class="field__control" type="search" name="q" value="<?= Html::encode($search) ?>"
                   placeholder="Search stores by name, company or city" aria-label="Search stores">
            <button class="btn btn--secondary" type="submit">Search</button>
            <?php if ($search !== ''): ?>
                <a class="btn btn--ghost" href="<?= Html::encode($dirUrl(AlphabetIndex::ALL, '')) ?>">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <nav class="alpha-nav" aria-label="Browse by letter">
        <?php
        $allActive = $letter === AlphabetIndex::ALL ? ' alpha-nav__item--active' : '';
?>
        <a class="alpha-nav__item<?= $allActive ?>" href="<?= Html::encode($dirUrl(AlphabetIndex::ALL, $search)) ?>">
            All <span class="alpha-nav__count"><?= ($counts[AlphabetIndex::ALL] ?? 0) ?></span>
        </a>
        <?php foreach (AlphabetIndex::letters() as $l): ?>
            <?php
    $count = ($counts[$l] ?? 0);
            $isActive = $letter === $l ? ' alpha-nav__item--active' : '';
            $isEmpty = $count === 0 ? ' alpha-nav__item--empty' : '';
            ?>
            <?php if ($count === 0): ?>
                <span class="alpha-nav__item<?= $isEmpty ?>"><?= Html::encode($l) ?></span>
            <?php else: ?>
                <a class="alpha-nav__item<?= $isActive ?>" href="<?= Html::encode($dirUrl($l, $search)) ?>"><?= Html::encode($l) ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>

    <?php if ($stores === []): ?>
        <div class="empty" style="padding: 2rem;">
            <div class="empty__title">No stores match</div>
            <p>Try a different letter or search term.</p>
        </div>
    <?php else: ?>
        <div class="store-grid">
            <?php foreach ($stores as $store): ?>
                <?php
                $chatUrl = $urlGenerator->generate('agent.chat.index', ['slug' => $store->slug]);
                $location = $store->location();
                ?>
                <article class="store-card">
                    <div class="store-card__body">
                        <h2 class="store-card__name"><?= Html::encode($store->name) ?></h2>
                        <?php if ($store->company !== null): ?>
                            <div class="store-card__meta"><?= Html::encode($store->company) ?></div>
                        <?php endif; ?>
                        <?php if ($location !== null): ?>
                            <div class="store-card__meta util-muted">📍 <?= Html::encode($location) ?></div>
                        <?php endif; ?>
                        <div class="store-card__meta util-muted">
                            <?= $store->documentCount ?> <?= $store->documentCount === 1 ? 'document' : 'documents' ?> ready
                        </div>
                    </div>
                    <div class="store-card__footer">
                        <a class="btn btn--primary btn--block" href="<?= Html::encode($chatUrl) ?>">Open chat</a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <?php if ($pageCount > 1): ?>
            <nav class="pager" aria-label="Pagination">
                <?php if ($page > 1): ?>
                    <a class="btn btn--secondary btn--sm" href="<?= Html::encode($pageUrl($page - 1)) ?>">← Previous</a>
                <?php else: ?>
                    <span class="btn btn--secondary btn--sm" aria-disabled="true" style="opacity:.5;">← Previous</span>
                <?php endif; ?>
                <span class="pager__status">Page <?= $page ?> of <?= $pageCount ?></span>
                <?php if ($page < $pageCount): ?>
                    <a class="btn btn--secondary btn--sm" href="<?= Html::encode($pageUrl($page + 1)) ?>">Next →</a>
                <?php else: ?>
                    <span class="btn btn--secondary btn--sm" aria-disabled="true" style="opacity:.5;">Next →</span>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>
