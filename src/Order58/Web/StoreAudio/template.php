<?php

declare(strict_types=1);

use App\Order58\Domain\StoreAudioFilter;
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
 * @var StoreSourceStatusFilter $sourceStatus
 * @var StoreAudioFilter $audio
 * @var string $letter
 * @var int $page
 * @var array<int, int> $audioCounts store source id => conversions, absent when none
 * @var string $providerDefault storage value of the current default transcription provider
 * @var array<string, string> $providerChoices storage value => label, in offer order
 * @var bool $settingsOpen render the settings dialog already open (the no-JavaScript path)
 */

$this->setTitle('Store audio');
$this->setParameter('breadcrumbs', [
    ['label' => 'Order58 Data Management', 'route' => 'order58.index'],
    ['label' => 'Store audio'],
]);

$base = $urlGenerator->generate('order58.store-audio');
$csrfField = (string) $csrf->hiddenInput();

// A route name, like every other link out of this page: this module may not name the one that owns the
// setting, and a name is not a namespace.
$providerUrl = $urlGenerator->generate('audio-to-text.settings.default-provider');

/**
 * @param array<string, string|int> $overrides
 */
$dirUrl = static function (array $overrides) use ($base, $search, $sourceStatus, $audio, $letter, $page): string {
    $state = [
        'q' => $search,
        'status' => $sourceStatus->value,
        'audio' => $audio->value,
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
    if ($state['status'] !== StoreSourceStatusFilter::All->value) {
        $params['status'] = $state['status'];
    }
    if ($state['audio'] !== StoreAudioFilter::All->value) {
        $params['audio'] = $state['audio'];
    }
    if ($state['letter'] !== AlphabetIndex::ALL) {
        $params['letter'] = $state['letter'];
    }
    if ((int) $state['page'] > 1) {
        $params['page'] = (string) $state['page'];
    }

    return $params === [] ? $base : $base . '?' . http_build_query($params);
};

// The no-JavaScript way into and back out of the dialog. Appended to the current directory URL rather
// than passed through $dirUrl — that helper deliberately emits only the five filter keys — so opening
// or dismissing the settings keeps the search, the filters and the page an administrator was already
// looking at.
$here = $dirUrl([]);
$settingsUrl = $here . (str_contains($here, '?') ? '&' : '?') . 'settings=1';
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title">Store audio</h1>
        <p class="page-header__subtitle">Pick a store to upload call recordings for. Every conversion belongs to the store you choose here.</p>
    </div>
    <?php
    // A route name again, for the same reason the cards use one: this page may not name the module it
    // is linking into. The list it opens is every store's conversions, which is why it lives up here
    // beside the picker rather than on any one store's page.
?>
    <div class="page-header__actions">
        <?php
        // A real link, not a button: with JavaScript off it reloads this page with the dialog already
        // open, which is the only way the one global setting stays reachable. `admin.js` intercepts it
        // and calls showModal() instead — the same progressive enhancement the chat report
        // drill-downs use.
        //
        // "Transcription settings" rather than "Settings" or "Audio settings": it governs
        // speech-to-text specifically, and nothing else on this page is configurable.
?>
        <a class="btn btn--secondary" href="<?= Html::encode($settingsUrl) ?>"
           data-a2t-settings-open>Transcription settings</a>
        <a class="btn" href="<?= Html::encode($urlGenerator->generate('audio-to-text.jobs')) ?>">All conversions</a>
    </div>
</div>

<?php
// The global transcription default.
//
// In a dialog because it is changed rarely and applies to every store, so a permanent card for it
// pushed the store picker — what an administrator actually came here for — below the fold.
//
// Native <dialog>: Escape, the backdrop and the focus trap come from the browser rather than from
// hand-written key handling, which is how every other dialog in this project is built. `open` is
// printed server-side only for the no-JavaScript path above.
//
// It submits on a button, never on change: this project's CSP is `script-src 'self'` so no inline
// handler is possible, and a select that saved silently would be worse anyway.
?>
<dialog class="a2t-confirm a2t-settings" data-a2t-settings-dialog
        aria-labelledby="a2t-settings-title"<?= $settingsOpen ? ' open' : '' ?>>
    <h2 class="a2t-confirm__title" id="a2t-settings-title">Transcription settings</h2>

    <form method="post" action="<?= Html::encode($providerUrl) ?>">
        <?= $csrfField ?>

        <div class="field">
            <label class="field__label" for="a2t-default-provider">Default transcription provider</label>
            <select class="field__control" id="a2t-default-provider" name="transcription_provider">
                <?php foreach ($providerChoices as $value => $label): ?>
                    <option value="<?= Html::encode($value) ?>"<?= $value === $providerDefault ? ' selected' : '' ?>>
                        <?= Html::encode($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="field__hint">
                Choose which provider should be selected automatically for new audio uploads.
            </div>
        </div>

        <p class="a2t-confirm__note">
            Applies to new uploads only, and can be overridden for a single upload on any store's page.
            Recordings already queued keep the provider they were queued with.
        </p>

        <div class="a2t-confirm__actions">
            <?php
            // A link, so the no-JavaScript path has a way back to the page without the dialog.
            // With JavaScript the click is intercepted and simply closes it.
?>
            <a class="btn btn--sm" href="<?= Html::encode($here) ?>" data-a2t-settings-close>Cancel</a>
            <button class="btn btn--primary btn--sm" type="submit">Save default</button>
        </div>
    </form>
</dialog>

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
$audioUrl = $urlGenerator->generate('audio-to-text.store', ['sourceId' => $store->sourceId]);
            $location = $store->locationLine();
            $conversions = $audioCounts[$store->sourceId] ?? 0;

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
                    <div class="store-card__badges">
                        <?php if (!$store->sourceActive): ?>
                            <span class="badge badge--error" title="Order58 reports this store as inactive">🔴 Source inactive</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="store-card__footer">
                    <?php if ($usable): ?>
                        <span class="btn btn--primary btn--block">Manage audio</span>
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
