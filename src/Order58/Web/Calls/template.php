<?php

declare(strict_types=1);

use App\Integration\Order58Recording\CallSummary;
use App\Integration\Order58Recording\RecordingChannel;
use App\Order58\Domain\CallImportHistoryRow;
use App\Order58\Domain\CallImportOutcome;
use App\Order58\Domain\Order58ImportStatus;
use App\Order58\Web\Calls\CallsAsset;
use App\Shared\Application\Time\AppTimeZone;
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
 * @var list<CallSummary> $calls today's calls, already filtered
 * @var array<string, non-empty-list<Order58ImportStatus>> $statuses keyed by call session id
 * @var string|null $problem why there are no calls to show
 * @var string $businessDate
 * @var string $provider the preselected transcription provider, a storage value
 * @var array<string, string> $providerChoices storage value => label
 * @var bool $importEnabled
 * @var bool $usingFixtures
 * @var list<CallImportHistoryRow> $history
 * @var list<RecordingChannel> $channels
 * @var AppTimeZone $appTimeZone
 * @var string $source
 * @var list<CallImportOutcome> $outcomes
 * @var list<Order58ImportStatus> $importStatuses
 */

$assetManager->register(CallsAsset::class);

$this->setTitle('Manage Order58 Calls');
$this->setParameter('breadcrumbs', [
    ['label' => 'Order58 Data Management', 'route' => 'order58.index'],
    ['label' => 'Manage Order58 Calls'],
]);

$csrfField = (string) $csrf->hiddenInput();
$pageUrl = $urlGenerator->generate('order58.calls');
$syncUrl = $urlGenerator->generate('order58.calls.sync');
$retryUrl = $urlGenerator->generate('order58.calls.retry');

/**
 * One call's status, as the discovery table shows it.
 *
 * The same rule the history uses, applied to whatever channels this call already has — so a call
 * imported yesterday reads "Completed" here for the same reason it does below, rather than by a second
 * calculation that could disagree.
 *
 * @param list<Order58ImportStatus>|null $channels
 */
$callState = static function (?array $channels): string {
    if ($channels === null || $channels === []) {
        return '<span class="util-muted">Not synced</span>';
    }

    /** @var non-empty-list<Order58ImportStatus> $channels */
    $outcome = CallImportOutcome::fromChannels($channels);

    return '<span class="badge badge--' . Html::encode($outcome->badge()) . '">'
        . Html::encode($outcome->label()) . '</span>';
};

/** A channel cell in the history table: its status, or a dash when it was never requested. */
$channelCell = static function (CallImportHistoryRow $row, RecordingChannel $channel): string {
    $item = $row->channel($channel);

    if ($item === null) {
        return '<td class="util-muted">—</td>';
    }

    $cell = '<td><span class="badge badge--' . Html::encode($item->status->badge()) . '">'
        . Html::encode($item->status->label()) . '</span>';

    // The sentence the worker recorded, already redacted and truncated on its way into the column.
    // Rendered as a title rather than a dialog: it is one line, and a dialog for one line is a click
    // that tells you what a tooltip would have.
    if ($item->errorMessage !== null && $item->errorMessage !== '') {
        $cell .= '<div class="field__hint" title="' . Html::encode($item->errorMessage) . '">'
            . Html::encode(mb_strimwidth($item->errorMessage, 0, 60, '…')) . '</div>';
    }

    if ($item->status === Order58ImportStatus::TooLarge && $item->bytes !== null) {
        $cell .= '<div class="field__hint">' . Html::encode(number_format($item->bytes / 1048576, 1) . ' MB')
            . '</div>';
    }

    return $cell . '</td>';
};
?>

<div class="page-header">
    <div>
        <h1 class="page-header__title">Manage Order58 Calls</h1>
        <p class="page-header__subtitle">
            Bring a store's call recordings into Audio to Text. Imported recordings appear on that
            store's own Audio to Text page, alongside anything uploaded by hand.
        </p>
    </div>
</div>

<?php if (!$importEnabled): ?>
    <div class="alert alert--warning">
        <strong>Recording import is turned off on this server.</strong>
        Calls can be listed, but Sync will not queue anything until
        <code>ORDER58_RECORDING_IMPORT_ENABLED</code> is set. The external recording service is reachable
        only from a server whose IP the client has allowlisted.
    </div>
<?php endif; ?>

<section class="card">
    <h2 class="card__title">Find today's calls</h2>

    <?php
    // A GET form: choosing a store and loading its calls is a readable, repeatable address, and it is
    // what keeps the provider from being contacted by a bare page load. `load=1` is the explicit ask.
?>
    <form method="get" action="<?= Html::encode($pageUrl) ?>" class="store-picker" role="group">
        <input type="hidden" name="load" value="1">
        <?php if ($source !== ''): ?>
            <input type="hidden" name="source" value="<?= Html::encode($source) ?>">
        <?php endif; ?>
        <label class="util-visually-hidden" for="o58-store">Store</label>
        <select class="field__control store-picker__select" id="o58-store" name="store">
            <option value="">Choose a store…</option>
            <?php foreach ($stores as $sourceId => $name): ?>
                <option value="<?= Html::encode((string) $sourceId) ?>"
                    <?= $selectedStore === $sourceId ? ' selected' : '' ?>>
                    <?= Html::encode($name . ' — #' . $sourceId) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn--primary" type="submit">Load today's calls</button>
    </form>

    <?php if ($stores === []): ?>
        <p class="field__hint">
            No active Order58 stores are mirrored yet. Run a store sync from Order58 Data Management first.
        </p>
    <?php endif; ?>
</section>

<?php if ($loaded): ?>
    <section class="card">
        <h2 class="card__title">
            Today's calls — <?= Html::encode($businessDate) ?>
        </h2>

        <?php if ($usingFixtures): ?>
            <?php // Never left implicit: a page showing invented calls must say so in plain words.?>
            <div class="alert alert--warning">
                <strong>Local fixtures.</strong> These calls are generated in this repository, not
                fetched from the recording service. Development and test only.
            </div>
        <?php endif; ?>

        <?php if ($problem !== null): ?>
            <p class="field__error"><?= Html::encode($problem) ?></p>
        <?php elseif ($calls === []): ?>
            <div class="empty">
                <div class="empty__title">No calls today</div>
                <p class="field__hint">This store has no calls for <?= Html::encode($businessDate) ?>.</p>
            </div>
        <?php else: ?>
            <form method="post" action="<?= Html::encode($syncUrl) ?>">
                <?= $csrfField ?>
                <input type="hidden" name="store" value="<?= Html::encode((string) $selectedStore) ?>">
                <?php if ($source !== ''): ?>
                    <input type="hidden" name="source" value="<?= Html::encode($source) ?>">
                <?php endif; ?>

                <div class="table-wrap" data-o58-calls>
                    <table class="table">
                        <thead>
                        <tr>
                            <th>
                                <label class="a2t-checkbox">
                                    <input type="checkbox" data-o58-select-all>
                                    <span class="util-visually-hidden">Select all of today's calls</span>
                                </label>
                            </th>
                            <th>Call session ID</th>
                            <th>Call time</th>
                            <th>Order ID</th>
                            <th>Sync status</th>
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
                                <td><?= $callState($statuses[$call->callSessionId] ?? null) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <p class="field__hint" data-o58-count>No calls selected</p>

                <div class="field">
                    <label class="field__label" for="o58-provider">Transcription provider</label>
                    <select class="field__control" id="o58-provider" name="transcription_provider">
                        <?php foreach ($providerChoices as $value => $label): ?>
                            <option value="<?= Html::encode($value) ?>"
                                <?= $value === $provider ? ' selected' : '' ?>>
                                <?= Html::encode($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="field__hint">
                        Applies to every call in this sync. Each recording records the engine it was
                        transcribed with.
                    </div>
                </div>

                <?php
            // Unticked every render, never remembered: it spends money, and an unticked checkbox
            // posts nothing at all, which is what makes "off" the reliable default.
?>
                <div class="field">
                    <label class="a2t-checkbox" for="o58-ai-audio">
                        <input type="checkbox" id="o58-ai-audio" name="generate_ai_audio" value="1">
                        <span>Generate clean AI audio after transcription</span>
                    </label>
                    <div class="field__hint">
                        Costs money. Off by default. The existing Text-to-Audio flow runs it once each
                        recording has been transcribed and its speakers are known.
                    </div>
                </div>

                <button class="btn btn--primary" type="submit"<?= $importEnabled ? '' : ' disabled' ?>>
                    Sync selected
                </button>

                <?php
// All three channels are attempted for every call. The administrator chooses calls, not
// channels: which of the three a merchant actually produces is the provider's answer.
?>
                <p class="field__hint">
                    Each call fetches its mixed, caller and callee recordings. A merchant without
                    separated channels reports those as <em>Not available</em>, which is normal.
                </p>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>

<section class="card">
    <h2 class="card__title">Sync history</h2>

    <?php if ($history === []): ?>
        <div class="empty">
            <div class="empty__title">Nothing imported yet</div>
            <p class="field__hint">
                Calls you sync appear here with a row per call and a column per recording.
            </p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Store</th>
                    <th>Call</th>
                    <th>Order</th>
                    <th>Call time</th>
                    <?php foreach ($channels as $channel): ?>
                        <th><?= Html::encode($channel->label()) ?></th>
                    <?php endforeach; ?>
                    <th>Overall</th>
                    <th>Provider</th>
                    <th>AI audio</th>
                    <th>Updated</th>
                    <th class="table__actions"></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($history as $row): ?>
                    <?php $outcome = $row->outcome(); ?>
                    <tr>
                        <td>
                            <?= Html::encode($row->storeName !== '' ? $row->storeName : 'Store') ?>
                            <div class="field__hint">#<?= Html::encode((string) $row->storeSourceId) ?></div>
                        </td>
                        <td><code><?= Html::encode($row->callSessionId) ?></code></td>
                        <td>
                            <?= $row->orderId === null
                ? '<span class="util-muted">—</span>'
                : Html::encode($row->orderId) ?>
                        </td>
                        <td><?= Html::encode($row->callTimeRaw) ?></td>
                        <?php foreach ($channels as $channel): ?>
                            <?= $channelCell($row, $channel) ?>
                        <?php endforeach; ?>
                        <td>
                            <span class="badge badge--<?= Html::encode($outcome->badge()) ?>">
                                <?= Html::encode($outcome->label()) ?>
                            </span>
                        </td>
                        <td><?= Html::encode($row->provider) ?></td>
                        <td><?= $row->generateAiAudio ? 'Yes' : 'No' ?></td>
                        <td class="util-muted"><?= Html::encode($appTimeZone->format($row->updatedAt)) ?></td>
                        <td class="table__actions">
                            <?php
            // One button per failed channel. Offered only where the repository would
            // actually act — a missing channel and an oversized recording are settled
            // facts, and a button that did nothing would be worse than none.
                    ?>
                            <?php foreach ($channels as $channel): ?>
                                <?php $item = $row->channel($channel); ?>
                                <?php if ($item !== null && $item->status->isRetryable()): ?>
                                    <form method="post" action="<?= Html::encode($retryUrl) ?>"
                                          class="inline-form">
                                        <?= $csrfField ?>
                                        <input type="hidden" name="import"
                                               value="<?= Html::encode((string) $item->id) ?>">
                                        <input type="hidden" name="store"
                                               value="<?= Html::encode((string) $row->storeSourceId) ?>">
                                        <button class="btn btn--sm" type="submit">
                                            Retry <?= Html::encode($channel->label()) ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p class="field__hint">
            Recordings import in the background. Reload this page to see progress; finished recordings
            also appear on the store's own Audio to Text page.
        </p>
    <?php endif; ?>
</section>
