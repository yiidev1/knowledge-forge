<?php

declare(strict_types=1);

use App\Order58\Domain\StoreAudioBreakdown;
use App\Order58\Domain\StoreAudioFilter;
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
 * @var StoreAudioFilter $audioDefault the value a request naming no `audio=` lands on, which is
 *      therefore the one the links below omit — see Action::defaultAudioFilter()
 * @var string $letter
 * @var int $page
 * @var array<int, StoreAudioBreakdown> $audioCounts
 * @var string $audioCountLabel
 * @var list<StoreAudioFilter> $audioOptions
 * @var bool $showsPractisable
 *      absent when the store has none. `total` is the same conversation count it always was.
 * @var array<int, int> $topicCounts how many of each store's orders carry a topic
 * @var string $providerDefault storage value of the current default transcription provider
 * @var array<string, string> $providerChoices storage value => label, in offer order
 * @var bool $settingsOpen render the settings dialog already open (the no-JavaScript path)
 */

$this->setTitle('Manage Orders');
// Its own trail. The audio picker's returns through Order58 Data Management; this page is reached from
// the Order Testing entry in the menu and belongs under that name.
$this->setParameter('breadcrumbs', [
    ['label' => 'Manage Orders'],
]);

// Every filter, the search and the pager post back to this page, not to the audio picker. One line, and
// the reason the two surfaces cannot send each other's traffic.
$base = $urlGenerator->generate('order-testing');
$csrfField = (string) $csrf->hiddenInput();

// A route name, like every other link out of this page: this module may not name the one that owns the
// setting, and a name is not a namespace.
// The same global setting, submitted through this surface's own address so that saving it returns the
// operator here rather than to the audio picker. The setting itself is one row and is shared; only where
// the redirect lands differs.
$providerUrl = $urlGenerator->generate('order-testing.settings.default-provider');

/**
 * @param array<string, string|int> $overrides
 */
$dirUrl = static function (array $overrides) use ($base, $search, $sourceStatus, $audio, $audioDefault, $letter, $page): string {
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
    // Against this surface's landing value, not against `All`. On a picker that opens filtered, the
    // chip that widens the list is the one that has to say so out loud; omitting it would produce the
    // bare address, which the action reads straight back as the filter the operator just left.
    if ($state['audio'] !== $audioDefault->value) {
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
        <h1 class="page-header__title">Manage Orders</h1>
        <p class="page-header__subtitle">Pick a store to manage its orders, recordings and conversations.</p>
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
        <?php
        // A POST, because it does work. Nothing imports demo orders automatically yet, so without
        // this the only way to see one is a shell command an administrator testing the workflow does
        // not have. It calls the same importer service the console command calls, under the same
        // lock — never a shelled-out `yii`.
        //
        // `data-busy-label` is the generic double-submit guard in admin.js: it disables the button
        // and relabels it while the browser waits. The real protection is the lock on the server,
        // which also holds across two tabs and across a future scheduled run; this only stops the
        // page looking idle during a sync.
?>
        <form method="post"
              action="<?= Html::encode($urlGenerator->generate('order-testing.sync-demo-orders')) ?>">
            <?= $csrfField ?>
            <button class="btn btn--secondary" type="submit"
                    data-busy-label="Syncing…">Sync demo orders</button>
        </form>
        <a class="btn btn--secondary" href="<?= Html::encode($settingsUrl) ?>"
           data-a2t-settings-open>Transcription settings</a>
        <?php /* TEMPORARILY HIDDEN at the client's request (2026-10-09).

        Commented out rather than deleted: this is expected to come back, and the markup is the whole
        of it. Nothing else changes — `audio-to-text.jobs` keeps its route, action and page, and the
        conversions list stays reachable from the sidebar and by URL. To restore, delete this comment
        wrapper and nothing else.

        `.page-header__actions` is a flex row, so removing one child leaves no gap behind it.

        <a class="btn" href="<?= Html::encode($urlGenerator->generate('audio-to-text.jobs')) ?>">All conversions</a>
        */ ?>
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

<?= $this->render(__DIR__ . '/_picker-body', [
    'urlGenerator' => $urlGenerator,
    'result' => $result,
    'search' => $search,
    'sourceStatus' => $sourceStatus,
    'audio' => $audio,
    'audioDefault' => $audioDefault,
    'letter' => $letter,
    'page' => $page,
    'audioCounts' => $audioCounts,
    // Decided with the counts, so the word and the number always describe one population.
    'audioCountLabel' => $audioCountLabel,
    'audioOptions' => $audioOptions,
    'showsPractisable' => $showsPractisable,
    'topicCounts' => $topicCounts,
    'base' => $base,
    'dirUrl' => $dirUrl,
    'storeRoute' => 'order-testing.store',
    /*
     * The action names what the card is about, which is now two things.
     *
     * The page behind it is the same either way — it lists a store's orders and its recordings — but
     * an operator who filtered to Case Orders and sees "Manage audio" has been told the card is
     * about the other population. Same destination, honest word.
     */
    'cta' => $audio === StoreAudioFilter::CaseOrders ? 'Manage orders' : 'Manage audio',
]) ?>
