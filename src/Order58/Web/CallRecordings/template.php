<?php

declare(strict_types=1);

use App\Integration\Order58Recording\CallSummary;
use App\Integration\Order58Recording\RecordingChannel;
use App\Order58\Web\CallRecordings\CallRecordingsAsset;
use App\Shared\Audio\RecordingAcquisition;
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

/** One channel's line: the provider's name for it, and where it has got to. */
$channelRow = static function (string $channel, \App\Shared\Audio\RecordingAcquisitionState $state): string {
    $name = RecordingChannel::fromStorage($channel)?->label() ?? $channel;

    return '<li class="o58-channel" data-o58-channel="' . Html::encode($channel) . '">'
        . '<span class="o58-channel__name">' . Html::encode($name) . '</span>'
        . '<span class="badge badge--' . Html::encode($state->badge()) . '" data-o58-channel-state>'
        . Html::encode($state->label()) . '</span>'
        . '</li>';
};

/**
 * One call's recordings: the word, the bar, the two sentences, and the three channels.
 *
 * ## Why there are two numbers here and not one
 *
 * The bar counts **channels checked** — how much of the asking is finished. The sentence under it counts
 * **what is actually here**. They are different questions and a single figure answering both would lie
 * in one direction or the other: a bar tracking availability would sit a third full for ever on a
 * merchant who only records the mixed call, and a bar tracking progress with no sentence beside it would
 * reach full and read as three recordings downloaded.
 *
 * Rendered by the server for the first paint and by `order58-recordings.js` for every poll after it, from
 * the same model — see {@see RecordingAcquisition}.
 */
$recordingsCell = static function (?RecordingAcquisition $acquisition) use ($channelRow): string {
    if ($acquisition === null) {
        return '<span class="badge badge--muted">Not downloaded</span>';
    }

    $outcome = $acquisition->outcome();

    $html = '<div class="o58-progress" data-o58-progress>'
        . '<span class="badge badge--' . Html::encode($outcome->badge()) . '" data-o58-outcome>'
        . Html::encode($outcome->label()) . '</span>';

    // `aria-valuetext` carries the words, not the number: a screen reader announcing "33 percent" would
    // repeat the one figure on this page that is not the authoritative one.
    $html .= '<div class="o58-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100"'
        . ' aria-valuenow="' . $acquisition->percentChecked() . '"'
        . ' aria-valuetext="' . Html::encode($acquisition->progressText()) . '" data-o58-bar>'
        . '<span class="o58-bar__fill" style="width: ' . $acquisition->percentChecked() . '%"></span>'
        . '</div>';

    $html .= '<span class="o58-progress__count" data-o58-progress-text>'
        . Html::encode($acquisition->progressText()) . '</span>';

    // The authoritative line. Always rendered, including while the bar is still moving, so the reader
    // never has to infer availability from a percentage.
    $html .= '<span class="o58-progress__availability" data-o58-availability>'
        . Html::encode($acquisition->availabilityText()) . '</span>';

    $html .= '<ul class="o58-channels" data-o58-channels>';

    foreach ($acquisition->channels as $channel => $state) {
        $html .= $channelRow($channel, $state);
    }

    return $html . '</ul></div>';
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
                <div class="table-wrap" data-o58-calls
                    data-o58-status="<?= Html::encode($statusUrl) ?>"
                    data-o58-store="<?= Html::encode((string) $selectedStore) ?>"
                    data-o58-active="<?= $anyActive ? '1' : '0' ?>">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>
                                    <label class="a2t-checkbox">
                                        <input type="checkbox" data-o58-select-all>
                                        <span class="util-visually-hidden">Select every call shown</span>
                                    </label>
                                </th>
                                <th>Call session ID</th>
                                <?php
                        // The provider's own string, printed exactly as sent. No zone label: it
                        // carries none, and appending one would be inventing a claim about a
                        // timestamp this application did not generate.
?>
                                <th>Call time</th>
                                <th>Order ID</th>
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
