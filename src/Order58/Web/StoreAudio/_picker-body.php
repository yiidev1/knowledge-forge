<?php

declare(strict_types=1);

use App\Order58\Domain\StoreAudioBreakdown;
use App\Order58\Domain\StoreAudioFilter;
use App\Order58\Domain\StoreDirectoryItem;
use App\Order58\Domain\StoreDirectoryResult;
use App\Order58\Domain\StoreSourceStatusFilter;
use App\Shared\Web\Support\AlphabetIndex;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * The store picker's body: search, filters, alphabet, cards, pager.
 *
 * Extracted so the surfaces that pick a store share one of these rather than three. Everything that
 * decides WHICH stores appear and what each card says is the action's; everything about how that is
 * drawn is here; and the only things a surface varies are where a card goes and what its button says.
 *
 * Rendered by `template.php` (Audio to Text), `order-testing.php` and `practice-orders.php`. Each keeps
 * its own page header above this, because those genuinely differ — one carries a sync button, another a
 * settings dialog — and folding them in would be the flag-threaded-through-a-template arrangement this
 * project has already rejected once.
 *
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var StoreDirectoryResult $result
 * @var string $search
 * @var StoreSourceStatusFilter $sourceStatus
 * @var StoreAudioFilter $audio
 * @var StoreAudioFilter $audioDefault
 * @var string $letter
 * @var int $page
 * @var array<int, StoreAudioBreakdown> $audioCounts
 * @var array<int, int> $topicCounts how many of each store's orders carry a topic; absent means none
 * @var string $base the surface's own landing address, which the search form posts back to
 * @var callable(array): string $dirUrl builds a URL carrying the current filters
 * @var string $storeRoute route NAME a card links to; a name is not a namespace, so isolation holds
 * @var string $cta the card button's words
 */
?>
<div class="dir-toolbar">
    <form class="dir-search" method="get" action="<?= Html::encode($base) ?>" role="search">
        <?php
        // A GET form submits ONLY its own fields, so without these the other two filters and the
        // letter would be dropped on every search — narrowing by name would silently widen the source
        // and audio axes back to everything. Emitted only when they are not already at their neutral
        // value, which keeps a plain search on a plain page at `?q=…` rather than carrying three
        // parameters that say nothing.
        //
        // `page` is deliberately NOT carried: a new search is a new result set, and page 7 of the old
        // one means nothing in it.
?>
        <?php if ($sourceStatus !== StoreSourceStatusFilter::All): ?>
            <input type="hidden" name="status" value="<?= Html::encode($sourceStatus->value) ?>">
        <?php endif; ?>
        <?php if ($audio !== $audioDefault): ?>
            <input type="hidden" name="audio" value="<?= Html::encode($audio->value) ?>">
        <?php endif; ?>
        <?php if ($letter !== AlphabetIndex::ALL): ?>
            <input type="hidden" name="letter" value="<?= Html::encode($letter) ?>">
        <?php endif; ?>
        <input class="field__control" type="search" name="q" value="<?= Html::encode($search) ?>"
               placeholder="Search stores by name, company, city or address" aria-label="Search stores">
        <button class="btn btn--secondary" type="submit">Search</button>
        <?php if ($search !== ''): ?>
            <a class="btn btn--ghost" href="<?= Html::encode($dirUrl(['q' => '', 'page' => 1])) ?>">Clear</a>
        <?php endif; ?>
    </form>
</div>

<div class="filter-bar" role="group" aria-label="Filter stores">
    <?php foreach (StoreSourceStatusFilter::cases() as $option): ?>
        <a class="filter-chip<?= $option === $sourceStatus ? ' filter-chip--active' : '' ?>" href="<?= Html::encode($dirUrl(['status' => $option->value, 'page' => 1])) ?>"><?= Html::encode($option->label()) ?></a>
    <?php endforeach; ?>
    <span class="filter-bar__sep" aria-hidden="true"></span>
    <?php // Independent of the source axis: an inactive store can still hold a year of recordings.?>
    <?php foreach (StoreAudioFilter::cases() as $option): ?>
        <a class="filter-chip<?= $option === $audio ? ' filter-chip--active' : '' ?>" href="<?= Html::encode($dirUrl(['audio' => $option->value, 'page' => 1])) ?>"><?= Html::encode($option->label()) ?></a>
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
        <div class="empty__icon" aria-hidden="true">🎙️</div>
        <div class="empty__title">No stores match</div>
        <?php if ($audio === StoreAudioFilter::WithAudio): ?>
            <p>No store has any conversions yet under these filters.</p>
        <?php else: ?>
            <p>Try a different letter or search term.</p>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="store-grid">
        <?php foreach ($result->items as $store): ?>
            <?php
/** @var StoreDirectoryItem $store */
// A route name, not a class. Neither this page nor the Audio-to-Text module may name the
// other's namespace — ModuleIsolationTest matches both literally — so the link is built
// from a string the router resolves. Store chat has linked out the same way since it was
// written.
$audioUrl = $urlGenerator->generate($storeRoute, ['sourceId' => $store->sourceId]);
            $location = $store->locationLine();
            // Absent key and zero mean the same thing, which is why the reader omits empty stores.
            $breakdown = $audioCounts[$store->sourceId] ?? StoreAudioBreakdown::none();
            // Orders, not recordings. A call recorded on three channels is one call, and the page
            // this card links to lists it as one row — so the card says one too.
            $conversions = $breakdown->orders;
            // Knowledge is not a gate here — a store with no documents can still have a recording
            // transcribed — but source-active is: a store Order58 reports as inactive is not
            // somewhere new recordings should be sent. Disabled the same way Store chat disables an
            // ineligible card, and enforced again on the store page, because a disabled button is a
            // hint rather than a rule.
            //
            // An inactive store's existing conversions stay reachable: the Store column on the global
            // conversions list links straight to its page.
            $usable = $store->sourceActive;
            $tag = $usable ? 'a' : 'div';
            $attrs = $usable
                ? 'class="store-card store-card--link" href="' . Html::encode($audioUrl) . '"'
                : 'class="store-card" aria-disabled="true"';
            ?>
            <<?= $tag ?> <?= $attrs ?>>
                <div class="store-card__body">
                    <div class="store-card__head">
                        <h2 class="store-card__name"><?= Html::encode($store->name) ?></h2>
                        <?php
                        // The audio total used to be a badge here as well as a strip of four numbers
                        // below. It is now one of the two metrics at the foot of the card and nowhere
                        // else: the same number in two places is two places that can disagree.
            ?>
                    </div>
                    <?php if ($store->company !== null): ?>
                        <div class="store-card__meta"><?= Html::encode($store->company) ?></div>
                    <?php endif; ?>
                    <?php if ($location !== null): ?>
                        <div class="store-card__meta util-muted">📍 <?= Html::encode($location) ?></div>
                    <?php endif; ?>
                    <div class="store-card__meta util-mono util-muted">Store #<?= $store->sourceId ?></div>
                    <?php
                    // Two numbers, because two are what this directory is actually asked for: how much
                    // audio a store has, and how much of its training is covered.
                    //
                    // It used to show the audio total split four ways — Mix, Customer, Agent, Other.
                    // That split made sense when a recording arrived one channel at a time and the
                    // question was which channels were still missing. Synchronisation now brings a
                    // call's channels together, so the split says the same thing on every card and the
                    // card is the wrong place to ask it; the store page still breaks it down per order.
                    //
                    // Both values are counted for the whole page before rendering, so a directory of
                    // 200 stores is two queries rather than 400.
                    $topics = $topicCounts[$store->sourceId] ?? 0;
            ?>
                    <dl class="store-card__metrics">
                        <div class="store-card__metric">
                            <dd class="store-card__metric-value<?= $conversions === 0 ? ' store-card__metric-value--zero' : '' ?>"><?=
                                $conversions ?></dd>
                            <dt class="store-card__metric-label">Audio Recordings</dt>
                        </div>
                        <div class="store-card__metric">
                            <dd class="store-card__metric-value<?= $topics === 0 ? ' store-card__metric-value--zero' : '' ?>"><?=
                                $topics ?></dd>
                            <?php
                            // "Topics Assigned" over "Orders with topics": the label is what the number
                            // is called, the line under it is what the number counts. Said here because
                            // the two are easy to confuse — the first version of this counted distinct
                            // topics and a store with three orders under one topic read as 1.
            ?>
                            <dt class="store-card__metric-label">Topics Assigned
                                <span class="store-card__metric-note">Orders with topics</span>
                            </dt>
                        </div>
                    </dl>
                    <div class="store-card__badges">
                        <?php if (!$store->sourceActive): ?>
                            <span class="badge badge--error" title="Order58 reports this store as inactive">🔴 Source inactive</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="store-card__footer">
                    <?php if ($usable): ?>
                        <span class="btn btn--primary btn--block"><?= Html::encode($cta) ?></span>
                    <?php else: ?>
                        <span class="btn btn--primary btn--block" aria-disabled="true">Audio unavailable — source inactive</span>
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

