<?php

declare(strict_types=1);

use App\Agent\Domain\PracticeStore;
use App\Shared\Web\Support\AlphabetIndex;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * The agent's practice store picker.
 *
 * Deliberately the same furniture as the store-chat picker — search, A–Z, cards, pager — because an
 * agent should not have to learn a second way to find a store. Only the list and the call to action
 * differ.
 *
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var list<PracticeStore> $stores
 * @var array<string, int> $counts
 * @var string $letter
 * @var string $search
 * @var int $page
 * @var int $pageCount
 * @var int $totalMatched
 * @var int $totalAvailable
 */

$this->setTitle('Practice Orders');

$base = $urlGenerator->generate('agent.practice-orders');

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
        <h1 class="page-header__title">Practice Orders</h1>
        <p class="page-header__subtitle">
            Choose a store to practise on. Listening to a real call and recreating its order is the
            exercise; nothing you do here changes the recording or the original order.
        </p>
    </div>
</div>

<?php if ($totalAvailable === 0): ?>
    <?php
    // Said plainly rather than as an error: there is nothing wrong, there is simply nothing with both
    // a recording and the order behind it yet.
    ?>
    <div class="empty">
        <div class="empty__icon" aria-hidden="true">🎧</div>
        <div class="empty__title">No stores to practise on yet</div>
        <p>A store appears here once it has a call recording linked to one of its orders.</p>
    </div>
<?php else: ?>
    <div class="dir-toolbar">
        <form class="dir-search" method="get" action="<?= Html::encode($base) ?>" role="search">
            <input class="field__control" type="search" name="q" value="<?= Html::encode($search) ?>"
                   placeholder="Search stores by name, company or store number" aria-label="Search stores">
            <button class="btn btn--secondary" type="submit">Search</button>
            <?php if ($search !== ''): ?>
                <a class="btn btn--ghost" href="<?= Html::encode($dirUrl(AlphabetIndex::ALL, '')) ?>">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <nav class="alpha-nav" aria-label="Browse by letter">
        <?php $allActive = $letter === AlphabetIndex::ALL ? ' alpha-nav__item--active' : ''; ?>
        <a class="alpha-nav__item<?= $allActive ?>" href="<?= Html::encode($dirUrl(AlphabetIndex::ALL, $search)) ?>">
            All <span class="alpha-nav__count"><?= ($counts[AlphabetIndex::ALL] ?? 0) ?></span>
        </a>
        <?php foreach (AlphabetIndex::letters() as $l): ?>
            <?php
            $count = ($counts[$l] ?? 0);
            $isActive = $letter === $l ? ' alpha-nav__item--active' : '';
            ?>
            <?php if ($count === 0): ?>
                <span class="alpha-nav__item alpha-nav__item--empty"><?= Html::encode($l) ?></span>
            <?php else: ?>
                <a class="alpha-nav__item<?= $isActive ?>" href="<?= Html::encode($dirUrl($l, $search)) ?>"><?= Html::encode($l) ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>

    <?php if ($stores === []): ?>
        <div class="empty">
            <div class="empty__title">No stores match</div>
            <p>Try a different letter or search term.</p>
        </div>
    <?php else: ?>
        <div class="store-grid">
            <?php foreach ($stores as $store): ?>
                <?php $subtitle = $store->subtitle(); ?>
                <article class="store-card">
                    <div class="store-card__body">
                        <h2 class="store-card__name"><?= Html::encode($store->name) ?></h2>
                        <?php if ($subtitle !== null): ?>
                            <div class="store-card__meta"><?= Html::encode($subtitle) ?></div>
                        <?php endif; ?>
                        <div class="store-card__meta util-muted util-mono">Store #<?= $store->sourceId ?></div>
                        <div class="store-card__meta util-muted">
                            <?= $store->recordingCount ?>
                            <?= $store->recordingCount === 1 ? 'recording' : 'recordings' ?> to practise on
                        </div>
                    </div>
                    <div class="store-card__footer">
                        <a class="btn btn--primary btn--block"
                           href="<?= Html::encode($urlGenerator->generate(
                               'agent.practice-orders.store',
                               ['sourceId' => $store->sourceId],
                           )) ?>">Open practice orders</a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <?= $this->render(dirname(__DIR__, 3) . '/Web/Shared/_partial/pager', [
            'page' => $page,
            'pageCount' => $pageCount,
            'pageUrl' => static fn(int $p): string => $pageUrl($p),
        ]) ?>
    <?php endif; ?>
<?php endif; ?>
