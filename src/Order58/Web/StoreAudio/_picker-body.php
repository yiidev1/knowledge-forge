<?php

declare(strict_types=1);

use App\Order58\Domain\StoreAudioBreakdown;
use App\Order58\Domain\StoreAudioFilter;
use App\Order58\Domain\StoreDirectoryItem;
use App\Order58\Domain\StoreDirectoryResult;
use App\Order58\Domain\StoreSourceStatusFilter;
use App\Shared\Audio\RecordingTypeLabels;
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
            $conversions = $breakdown->total;
            // CSS cannot match on a number's value, so the zero state is a class from here.
            $audioValue = static fn(int $n): string => 'store-card__audio-value'
                . ($n === 0 ? ' store-card__audio-value--zero' : '');

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
                        <?php // Conversions, not jobs: a separate Customer + Agent upload is one.?>
                        <span
                            class="store-card__kb-count<?= $conversions > 0 ? ' store-card__kb-count--ok' : '' ?>"
                            title="<?= $conversions ?> conversion<?= $conversions === 1 ? '' : 's' ?> uploaded for this store"
                        >🎙 <?= $conversions ?></span>
                    </div>
                    <?php if ($store->company !== null): ?>
                        <div class="store-card__meta"><?= Html::encode($store->company) ?></div>
                    <?php endif; ?>
                    <?php if ($location !== null): ?>
                        <div class="store-card__meta util-muted">📍 <?= Html::encode($location) ?></div>
                    <?php endif; ?>
                    <div class="store-card__meta util-mono util-muted">Store #<?= $store->sourceId ?></div>
                    <?php
                    // How the total above splits by recording type. Total is NOT repeated here — it is
                    // the badge beside the name, and the same number in two places is two places that
                    // can disagree.
                    //
                    // Always rendered, zeros included, so every card is the same height and a page of
                    // 36 does not jag as the counts change. The four cells share one width for the same
                    // reason: the numbers line up down the grid and can be compared by eye.
            ?>
                    <?php
                    // The same three names the Audio-to-Text pages use, read from the shared map rather
                    // than typed out again here. This page may not name that module at all (see
                    // RecordingTypeLabels), and three counts labelled one way on this card and another
                    // on the page they link to is exactly the confusion the shared map prevents.
                    //
                    // The SHORT form, because this strip gives each label a quarter of a card and the
                    // labels are `white-space: nowrap`: "Mix / Common" was wider than its column and so
                    // drew over "Customer" beside it rather than wrapping or clipping. Only that one
                    // differs; the other two are the same single words either way.
                    $typeLabel = static fn(string $stored): string
                        => RecordingTypeLabels::shortForStorageValue($stored) ?? $stored;

            // The full wording on hover, but only where shortening actually dropped something.
            // A tooltip on "Customer" that reads "Customer" is noise; one on "Mix" that reads
            // "Mix / Common" is the word this cell had to give up to fit, and it is the word
            // that explains why uploads made before recording types existed are counted here.
            $typeTitle = static function (string $stored): string {
                $full = RecordingTypeLabels::forStorageValue($stored);

                return $full === null || $full === RecordingTypeLabels::shortForStorageValue($stored)
                    ? ''
                    : ' title="' . Html::encode($full) . '"';
            };
            ?>
                    <dl class="store-card__audio" aria-label="Recordings by type">
                        <div class="store-card__audio-cell"<?= $typeTitle('MIXED') ?>>
                            <dt class="store-card__audio-label"><?= Html::encode($typeLabel('MIXED')) ?></dt>
                            <dd class="<?= $audioValue($breakdown->mixed) ?>"><?= $breakdown->mixed ?></dd>
                        </div>
                        <div class="store-card__audio-cell"<?= $typeTitle('CALLER') ?>>
                            <dt class="store-card__audio-label"><?= Html::encode($typeLabel('CALLER')) ?></dt>
                            <dd class="<?= $audioValue($breakdown->caller) ?>"><?= $breakdown->caller ?></dd>
                        </div>
                        <div class="store-card__audio-cell"<?= $typeTitle('CALLEE') ?>>
                            <dt class="store-card__audio-label"><?= Html::encode($typeLabel('CALLEE')) ?></dt>
                            <dd class="<?= $audioValue($breakdown->callee) ?>"><?= $breakdown->callee ?></dd>
                        </div>
                        <?php
                // `recording_type` is nullable, so these three do not add up to the total.
                // What is left over is reported honestly rather than folded into Mixed, which
                // would claim a channel the uploader never stated.
            ?>
                        <div class="store-card__audio-cell"
                             title="Other includes recordings that named no type at all, including older recordings and separate uploads. The stored types are MIXED, CALLER and CALLEE.">
                            <dt class="store-card__audio-label">Other</dt>
                            <dd class="<?= $audioValue($breakdown->other()) ?>"><?= $breakdown->other() ?></dd>
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

