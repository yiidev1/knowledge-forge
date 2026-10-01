<?php

declare(strict_types=1);

use App\Integration\Order58Recording\CallSummary;
use App\Integration\Order58Recording\RecordingChannel;
use App\Order58\Web\CallRecordings\CallRecordingsAsset;
use App\Shared\Audio\RecordingAcquisition;
use App\Shared\Audio\RecordingAcquisitionState;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\Csrf;

/**
 * @var Yiisoft\View\WebView $this
 * @var Yiisoft\Assets\AssetManager $assetManager
 * @var UrlGeneratorInterface $urlGenerator
 * @var Csrf $csrf
 * @var array<int, string> $stores source id => name, active stores only
 * @var int|null $selectedStore
 * @var bool $loaded whether the provider was asked for calls on this request
 * @var string $today the current business date, as the date field's ceiling
 * @var list<CallSummary> $calls the chosen day's calls, already filtered
 * @var array<string, RecordingAcquisition> $statuses keyed by call session id
 * @var string $statusUrl the one endpoint this page polls for every visible call at once
 * @var string $historyUrl what has been downloaded before, on its own page
 * @var string|null $problem why there are no calls to show
 * @var string $businessDate the day being shown — today unless the operator chose another
 * @var bool $importEnabled
 * @var bool $usingFixtures
 * @var string $source
 */

$assetManager->register(CallRecordingsAsset::class);

$this->setTitle('Order58 Call Recordings');
$this->setParameter('breadcrumbs', [
    ['label' => 'Order58 Data Management', 'route' => 'order58.index'],
    ['label' => 'Order58 Call Recordings'],
]);

$csrfField = (string) $csrf->hiddenInput();
$pageUrl = $urlGenerator->generate('order58.call-recordings');
$downloadUrl = $urlGenerator->generate('order58.call-recordings.download');

/**
 * One channel as a step: its mark, the provider's name for it, and where it has got to.
 *
 * The mark is the transcription panel's own — ○ pending, ◉ active, ✓ complete, ✗ failed, – skipped —
 * so a reader scanning the column sees the shape of the call before reading a word of it.
 */
$channelRow = static function (string $channel, RecordingAcquisitionState $state): string {
    $name = RecordingChannel::fromStorage($channel)?->label() ?? $channel;

    return '<li class="o58-channel" data-o58-channel="' . Html::encode($channel) . '"'
        . ' data-state="' . Html::encode($state->step()) . '">'
        . '<span class="o58-channel__mark" aria-hidden="true"></span>'
        . '<span class="o58-channel__name">' . Html::encode($name) . '</span>'
        . '<span class="badge badge--' . Html::encode($state->badge()) . '" data-o58-channel-state>'
        . Html::encode($state->label()) . '</span>'
        . '</li>';
};

/**
 * One call's recordings: a progress panel while it is happening, and a plain result once it is not.
 *
 * ## The bar is for the thing that is moving, and nothing else
 *
 * A finished call used to keep a full bar and the line "3 of 3 recordings checked" for ever. Both were
 * true and neither was any use: the question a bar answers is "how far along is this", and once the
 * answer is "it is done" the bar is noise on every row of the table — most of which are finished, most
 * of the time. So the panel is drawn **only while a channel is still pending or downloading**, and a
 * settled call shows its word and its three channels and stops.
 *
 * ## While it IS moving, it is the transcription panel
 *
 * Same structure, same classes, same step marks as the card the Audio-to-Text page already uses for a
 * transcription: a heading, one overall bar, a checklist of steps, and a line saying what is happening
 * now. A download and a transcription are different work, but they are the same product, and a reader
 * who has learnt one of these should not have to learn the other.
 *
 * ## Two numbers, because one of them would lie
 *
 * The bar counts **channels checked** — how much of the asking is finished. The line under it counts
 * **what is actually here**. A bar tracking availability would sit a third full for ever on a merchant
 * who only records the mixed call; a bar with no sentence beside it would reach full and read as three
 * recordings downloaded.
 *
 * Rendered by the server for the first paint and by `order58-recordings.js` for every poll after it,
 * from the same model — see {@see RecordingAcquisition}.
 */
$recordingsCell = static function (?RecordingAcquisition $acquisition) use ($channelRow): string {
    if ($acquisition === null) {
        return '<span class="badge badge--muted">Not downloaded</span>';
    }

    $outcome = $acquisition->outcome();

    $html = '<div class="o58-recordings" data-o58-progress>'
        . '<span class="badge badge--' . Html::encode($outcome->badge()) . '" data-o58-outcome>'
        . Html::encode($outcome->label()) . '</span>';

    if ($acquisition->isActive()) {
        $html .= '<div class="o58-panel" data-o58-panel>'
            . '<div class="o58-panel__head">'
            . '<span class="o58-panel__title">Overall progress</span>'
            // `aria-hidden`, because the bar below carries the same figure as `aria-valuetext` in
            // words. Announcing it twice is how a progress panel becomes unusable with a screen reader.
            . '<span class="o58-panel__count" data-o58-progress-text aria-hidden="true">'
            . Html::encode($acquisition->progressText()) . '</span>'
            . '</div>'
            // A real <progress>, as the transcription card uses: the browser draws it, announces it,
            // and respects reduced-motion settings without any of that being reimplemented here.
            . '<progress class="o58-panel__bar" max="100" value="' . $acquisition->percentChecked() . '"'
            . ' aria-label="' . Html::encode($acquisition->progressText()) . '" data-o58-bar></progress>'
            . '<p class="o58-panel__detail" role="status" data-o58-current>'
            . Html::encode((string) $acquisition->currentStep()) . '</p>'
            . '</div>';
    }

    $html .= '<ul class="o58-channels" data-o58-channels>';

    foreach ($acquisition->channels as $channel => $state) {
        $html .= $channelRow($channel, $state);
    }

    $html .= '</ul>';

    // Always last and always present, settled or not: what actually arrived is the one fact a reader
    // came for, and it must never have to be inferred from a count of channels.
    $html .= '<span class="o58-recordings__availability" data-o58-availability>'
        . Html::encode($acquisition->availabilityText()) . '</span>';

    return $html . '</div>';
};

?>

<div class="page-header">
    <div>
        <h1 class="page-header__title">Order58 Call Recordings</h1>
        <p class="page-header__subtitle">
            Download a store's call recordings so they can be listened to. Each call brings down up to
            three separate recordings — the mixed call and each side of it — and they appear on that
            store's Audio to Text page, ready to play. Nothing is transcribed until you ask for it,
            one recording at a time.
        </p>
    </div>
    <?php
    // Up here rather than beside the table, because it leaves this page rather than changing it — the
    // same placement the calls page uses for its own history link.
?>
    <div class="page-header__actions">
        <a class="btn btn--secondary" href="<?= Html::encode($historyUrl) ?>">View Download History</a>
    </div>
</div>

<?php if (!$importEnabled): ?>
    <div class="alert alert--warning">
        <strong>Recording download is turned off on this server.</strong>
        Calls can be listed, but nothing will be downloaded until
        <code>ORDER58_RECORDING_IMPORT_ENABLED</code> is set. The external recording service is reachable
        only from a server whose IP the client has allowlisted.
    </div>
<?php endif; ?>

<section class="card">
    <h2 class="card__title">Find a store's calls</h2>

    <?php
// A GET form: choosing a store and loading its calls is a readable, repeatable address, and it is
// what keeps the provider from being contacted by a bare page load. `load=1` is the explicit ask.
?>
    <form method="get" action="<?= Html::encode($pageUrl) ?>" class="store-picker" role="group">
        <input type="hidden" name="load" value="1">
        <?php if ($source !== ''): ?>
            <input type="hidden" name="source" value="<?= Html::encode($source) ?>">
        <?php endif; ?>
        <label class="util-visually-hidden" for="o58r-store">Store</label>
        <select class="field__control store-picker__select" id="o58r-store" name="store">
            <option value="">Choose a store…</option>
            <?php foreach ($stores as $sourceId => $name): ?>
                <option value="<?= Html::encode((string) $sourceId) ?>"
                    <?= $selectedStore === $sourceId ? ' selected' : '' ?>>
                    <?= Html::encode($name . ' — #' . $sourceId) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    // Defaults to today and never offers a later day: the provider cannot have recorded a call that
    // has not happened.
?>
        <label class="field__label store-picker__label" for="o58r-date">Date</label>
        <input class="field__control store-picker__date" type="date" id="o58r-date" name="date"
            value="<?= Html::encode($businessDate) ?>" max="<?= Html::encode($today) ?>">
        <button class="btn btn--primary" type="submit">Load calls</button>
    </form>

    <?php if ($stores === []): ?>
        <p class="field__hint">
            No active Order58 stores are mirrored yet. Run a store sync from Order58 Data Management first.
        </p>
    <?php endif; ?>
</section>

<?php if ($loaded): ?>
    <section class="card">
        <h2 class="card__title">Calls — <?= Html::encode($businessDate) ?></h2>

        <?php if ($usingFixtures): ?>
            <?php // Never left implicit: a page showing invented calls must say so in plain words.
    ?>
            <div class="alert alert--warning">
                <strong>Local fixtures.</strong> These calls are generated in this repository, not
                fetched from the recording service. Development and test only.
            </div>
        <?php endif; ?>

        <?php if ($problem !== null): ?>
            <p class="field__error"><?= Html::encode($problem) ?></p>
        <?php elseif ($calls === []): ?>
            <div class="empty">
                <div class="empty__title">No calls</div>
                <p class="field__hint">
                    This store has no calls for <?= Html::encode($businessDate) ?>.
                </p>
            </div>
        <?php else: ?>
            <form method="post" action="<?= Html::encode($downloadUrl) ?>">
                <?= $csrfField ?>
                <input type="hidden" name="store" value="<?= Html::encode((string) $selectedStore) ?>">
                <?php /* The day these rows are FROM. Without it the request re-fetches today and
                         refuses every selection made on any other date. */ ?>
                <input type="hidden" name="date" value="<?= Html::encode($businessDate) ?>">
                <?php if ($source !== ''): ?>
                    <input type="hidden" name="source" value="<?= Html::encode($source) ?>">
                <?php endif; ?>

                <?php
                // The polling hooks. `data-o58-active` decides whether the script starts at all, so a
                // page of finished downloads makes no requests — the commonest case by far, since most
                // visits are to look at a day rather than to download one.
                $anyActive = false;

foreach ($statuses as $acquisition) {
    $anyActive = $anyActive || $acquisition->isActive();
}
?>
                <div class="table-wrap o58-wide" data-o58-calls
                    data-o58-status="<?= Html::encode($statusUrl) ?>"
                    data-o58-store="<?= Html::encode((string) $selectedStore) ?>"
                    data-o58-active="<?= $anyActive ? '1' : '0' ?>">
                    <table class="table o58-table">
                        <thead>
                            <tr>
                                <th class="o58-table__select">
                                    <label class="a2t-checkbox">
                                        <input type="checkbox" data-o58-select-all>
                                        <span class="util-visually-hidden">Select every call shown</span>
                                    </label>
                                </th>
                                <th class="o58-table__id">Call session ID</th>
                                <?php
                        // The provider's own string, printed exactly as sent. No zone label: it
                        // carries none, and appending one would be inventing a claim about a
                        // timestamp this application did not generate.
?>
                                <th class="o58-table__time">Call time</th>
                                <th class="o58-table__order">Order ID</th>
                                <th>Recordings</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($calls as $call): ?>
                                <tr>
                                    <td>
                                        <label class="a2t-checkbox">
                                            <input type="checkbox" name="calls[]"
                                                value="<?= Html::encode($call->callSessionId) ?>">
                                            <span class="util-visually-hidden">
                                                Select call <?= Html::encode($call->callSessionId) ?>
                                            </span>
                                        </label>
                                    </td>
                                    <td><code><?= Html::encode($call->callSessionId) ?></code></td>
                                    <td><?= Html::encode($call->callTime) ?></td>
                                    <td>
                                        <?= $call->orderId === ''
                ? '<span class="util-muted">—</span>'
                : Html::encode($call->orderId) ?>
                                    </td>
                                    <td data-o58-call="<?= Html::encode($call->callSessionId) ?>">
                                        <?= $recordingsCell($statuses[$call->callSessionId] ?? null) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <p class="field__hint" data-o58-count>No calls selected</p>

                <?php
                // Said once, here, because it is the only thing about this page that surprises people:
                // a selection does not arrive all at once. One recording is fetched at a time, on
                // purpose — downloading sixty files in parallel is how a server runs out of memory and
                // a third party starts answering 429.
?>
                <p class="field__hint">
                    Recordings are downloaded one at a time, so a large selection arrives over several
                    minutes. You can leave this page — the download continues on the server.
                </p>

                <div class="form-actions">
                    <button class="btn btn--primary" type="submit" <?= $importEnabled ? '' : 'disabled' ?>>
                        Download selected
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>
