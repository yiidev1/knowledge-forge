<?php

declare(strict_types=1);

use App\AudioToText\Domain\AudioStore;
use App\AudioToText\Domain\ConversationMode;
use App\AudioToText\Domain\GroupKey;
use App\AudioToText\Domain\OrderId;
use App\AudioToText\Domain\RecordingType;
use App\AudioToText\Domain\StoreOrderGroup;
use App\AudioToText\Domain\StoreRecordingSlot;
use App\AudioToText\Domain\TranscriptionProvider;
use App\AudioToText\Domain\WorkerStatusView;
use App\AudioToText\Web\AudioToTextIcons;
use App\AudioToText\Web\AudioToTextViews;
use App\AudioToText\Web\AudioToTextRoute;
use App\AudioToText\Web\Job\Store\StoreAudioAsset;
use App\Shared\Application\Time\AppTimeZone;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\Csrf;

/**
 * @var Yiisoft\View\WebView $this
 * @var Yiisoft\Assets\AssetManager $assetManager
 * @var UrlGeneratorInterface $urlGenerator
 * @var Csrf $csrf
 * @var AudioStore $store
 * @var ConversationMode $mode
 * @var string $orderId what was typed into the optional Order ID field, '' on a fresh form
 * @var RecordingType $selectedType which recording the upload form is set to
 * @var bool $uploadOpen whether the upload dialog should render already open
 * @var array<string, list<string>> $errors
 * @var list<StoreOrderGroup> $groups one row per order, newest activity first
 * @var int $total
 * @var int $page
 * @var int $pageCount
 * @var WorkerStatusView $worker
 * @var string $maxUploadLabel
 * @var string $maxDurationLabel
 * @var string $extensionList
 * @var int|null $retentionHours
 * @var string $combinedLimitLabel
 * @var bool $canUpload
 * @var AppTimeZone $appTimeZone
 * @var TranscriptionProvider $provider currently selected, and what all recording cards preselect
 * @var list<TranscriptionProvider> $providerChoices every provider, usable or not
 * @var array<string, bool> $providerUsable storage value => can this server run it (local check only)
 * @var TranscriptionProvider $globalDefault the stored setting, reported as-is and never rewritten here
 * @var bool $ttsConfigured whether clean AI audio can be generated on this server at all
 */

$assetManager->register(StoreAudioAsset::class);

$this->setTitle($store->name . ' — audio');
$this->setParameter('breadcrumbs', [
    ['label' => 'Order58 Data Management', 'route' => 'order58.index'],
    ['label' => 'Store audio', 'route' => 'order58.store-audio'],
    ['label' => $store->name],
]);

$csrfField = (string) $csrf->hiddenInput();
$storeUrl = $urlGenerator->generate(AudioToTextRoute::STORE, ['sourceId' => $store->sourceId]);
$pageUrl = static fn(int $p): string => $storeUrl . ($p > 1 ? '?page=' . $p : '');

/**
 * @param list<string> $messages
 */
$fieldErrors = static function (array $messages): string {
    $html = '';
    foreach ($messages as $message) {
        $html .= '<div class="field__error">' . Html::encode($message) . '</div>';
    }

    return $html;
};

/**
 * The optional Order ID field.
 *
 * Still a closure rather than inline markup: the dialog renders it once today, and keeping the field's
 * shape in one place is what stopped the three cards it replaced from drifting apart.
 *
 * **Optional, and it says so.** The rule is one line in {@see OrderId} and is enforced on the server;
 * this only reports it. A rejected value is rendered back into the field rather than discarded — it is
 * usually a typo in something the operator had to read off another screen, and making them find it
 * again would be the worst part of the mistake.
 */
$orderIdField = static function (string $id) use ($orderId, $errors, $fieldErrors): string {
    return '<div class="field">'
        . '<label class="field__label" for="' . Html::encode($id) . '">Order ID</label>'
        . '<input class="field__control' . (isset($errors['order_id']) ? ' field__control--error' : '')
        . '" id="' . Html::encode($id) . '" type="text" name="order_id" inputmode="numeric"'
        . ' autocomplete="off" value="' . Html::encode($orderId) . '">'
        . $fieldErrors($errors['order_id'] ?? [])
        . '<div class="field__hint">Optional. Example: 16513791</div>'
        . '</div>';
};

/**
 * The provider select.
 *
 * Unchanged from when three cards each rendered it: the options, the disabled-and-labelled treatment
 * for a provider this machine cannot run, and the note about the global default are all as they were.
 *
 * ## Always rendered, never hidden
 *
 * Every provider appears every time, including ones this machine cannot currently run. Hiding the
 * field when only one provider works looked tidy and was wrong twice over: an administrator could not
 * see which engine their upload would use, and a broken install was indistinguishable from a
 * single-provider one. An unusable provider is shown `disabled` and labelled instead, which says the
 * true thing — the choice exists, this machine cannot make it yet.
 *
 * Availability comes from LOCAL configuration that the action already computed. Rendering this page
 * contacts no provider.
 *
 * `disabled` on an option is a courtesy, not the control: the server refuses an unusable provider
 * whatever is posted.
 */
$providerField = static function (string $id) use (
    $provider,
    $providerChoices,
    $providerUsable,
    $globalDefault,
    $errors,
    $fieldErrors
): string {
    $options = '';
    foreach ($providerChoices as $choice) {
        $usable = $providerUsable[$choice->value] ?? false;

        $options .= '<option value="' . Html::encode($choice->value) . '"'
            . ($usable ? '' : ' disabled')
            . ($choice === $provider ? ' selected' : '') . '>'
            . Html::encode($choice->label() . ($usable ? '' : ' — Not configured'))
            . '</option>';
    }

    // One line per unusable provider, in plain words. Deliberately says nothing about WHICH setting is
    // missing: that is the operator's business and it belongs in the log, not on a page anyone with an
    // upload to make can read.
    $unavailable = '';
    foreach ($providerChoices as $choice) {
        if (($providerUsable[$choice->value] ?? false) === false) {
            $unavailable .= '<div class="field__hint">'
                . Html::encode($choice->shortLabel() . ' is not currently configured on this server.')
                . '</div>';
        }
    }

    // The configured default names something that cannot run. Said out loud rather than papered over:
    // the preselected provider below is NOT the global default, and an administrator who does not know
    // that would reasonably assume their upload used the engine the settings page advertises.
    $defaultNote = ($providerUsable[$globalDefault->value] ?? false) === false
        ? '<div class="field__hint">'
            . Html::encode(
                'The global default is ' . $globalDefault->label() . ', which is not available on this '
                . 'server, so another provider is selected for this upload. The global setting has not '
                . 'been changed.',
            )
            . '</div>'
        : '<div class="field__hint">'
            . Html::encode('Global default: ' . $globalDefault->label() . '.')
            . ' This choice applies only to this upload.'
            . '</div>';

    return '<div class="field">'
        . '<label class="field__label" for="' . Html::encode($id) . '">Transcription provider</label>'
        . '<select class="field__control' . (isset($errors['transcription_provider']) ? ' field__control--error' : '')
        . '" id="' . Html::encode($id) . '" name="transcription_provider">' . $options . '</select>'
        . $fieldErrors($errors['transcription_provider'] ?? [])
        . $defaultNote
        . $unavailable
        . '<div class="field__hint">Speech recognition only — speakers are always worked out on this server.</div>'
        . '</div>';
};

/**
 * The AI-audio opt-in.
 *
 * **Unchecked, always.** It is not sticky and does not remember a previous upload: this spends money
 * with a third party, and a checkbox that quietly stayed on would spend it on recordings nobody decided
 * to spend it on. The cost is stated on the control rather than a click later.
 *
 * Nothing about this makes the upload wait. The preference is recorded on the conversation and acted on
 * afterwards by a worker — no speech provider is contacted in this request.
 */
$aiAudioField = static function (string $id) use ($ttsConfigured): string {
    $hint = $ttsConfigured
        ? 'Costs money. Off by default. A clean synthetic reading of the transcript, for agents who '
            . 'cannot follow the original recording.'
        : 'Not configured on this server yet, so nothing would be generated.';

    return '<div class="field">'
        . '<label class="a2t-checkbox" for="' . Html::encode($id) . '">'
        . '<input type="checkbox" id="' . Html::encode($id) . '" name="generate_ai_audio" value="1"'
        . ($ttsConfigured ? '' : ' disabled') . '>'
        . '<span>Generate clean AI audio after transcription</span>'
        . '</label>'
        . '<div class="field__hint">' . Html::encode($hint) . '</div>'
        . '</div>';
};

// Keep errors readable for a submission from an older, already-open paired-upload form.
$formErrors = array_merge($errors['form'] ?? [], $errors['customer_audio'] ?? [], $errors['agent_audio'] ?? []);

/**
 * A duration as a person reads it.
 *
 * `3:26` rather than `206.3s`: this sits inside a play control, where the number is a length of
 * listening rather than a measurement.
 */
$clock = static function (?float $seconds): string {
    if ($seconds === null || $seconds <= 0) {
        return '--:--';
    }

    $whole = (int) round($seconds);

    return sprintf('%d:%02d', intdiv($whole, 60), $whole % 60);
};

// Every address a control needs is generated here and printed into the control, never assembled in
// the browser from an id. A deployment prefix, the router's own escaping and the shape of each route
// are all things this side knows and the script deliberately does not — the same rule the upload
// flow already follows with `data-a2t-done`.
$originalUrl = static fn(string $jobPublicId): string => $urlGenerator->generate(
    AudioToTextRoute::JOB_ORIGINAL_FILE,
    ['publicId' => $jobPublicId],
);
$generatedUrl = static fn(string $jobPublicId): string => $urlGenerator->generate(
    AudioToTextRoute::JOB_AI_AUDIO_FILE,
    ['publicId' => $jobPublicId],
);
$fragmentUrl = static fn(string $jobPublicId): string => $urlGenerator->generate(
    AudioToTextRoute::JOB_REVIEW_FRAGMENT,
    ['publicId' => $jobPublicId],
);
$fullReviewUrl = static fn(string $jobPublicId): string => $urlGenerator->generate(
    AudioToTextRoute::JOB_REVIEW,
    ['publicId' => $jobPublicId],
);
$groupUrl = static fn(string $route, GroupKey $key): string => $urlGenerator->generate(
    $route,
    ['sourceId' => $store->sourceId, 'groupKey' => $key->value],
);

/**
 * One recording, as a table cell.
 *
 * Play, and a way in. Everything else about the recording lives behind Details, because a cell one
 * third of a column wide is not where an administrator reads a transcript.
 *
 * The play control is a `<button>` rather than an `<audio>` element: sixty native players on one page
 * is sixty pieces of browser chrome and sixty preloads. One shared controller in
 * `audio-store.js` plays them, which is also what makes "only one at a time" possible.
 */
$slotCellInner = static function (StoreRecordingSlot $slot) use (
    $clock,
    $originalUrl,
    $fragmentUrl,
    $fullReviewUrl
): string {
    $html = '<div class="a2t-slot">';

    if ($slot->hasOriginalAudio) {
        $html .= '<button class="a2t-play" type="button"'
            . ' data-a2t-play="' . Html::encode($originalUrl($slot->jobPublicId)) . '"'
            . ' aria-label="' . Html::encode('Play ' . $slot->label() . ' recording') . '">'
            . '<span class="a2t-play__icon" aria-hidden="true"></span>'
            . '<span class="a2t-play__time">' . Html::encode($clock($slot->durationSeconds)) . '</span>'
            . '</button>';
    }

    if ($slot->isReviewable()) {
        $html .= '<button class="a2t-slot__link" type="button"'
            . ' data-a2t-details="' . Html::encode($fragmentUrl($slot->jobPublicId)) . '"'
            . ' data-a2t-details-full="' . Html::encode($fullReviewUrl($slot->jobPublicId)) . '"'
            . ' data-a2t-details-label="' . Html::encode($slot->label()) . '">Details</button>';
    }

    return $html . '</div>';
};

$slotCell = static function (?StoreRecordingSlot $slot) use (
    $clock,
    $originalUrl,
    $fragmentUrl,
    $fullReviewUrl,
    $slotCellInner,
    $appTimeZone
): string {
    if ($slot === null) {
        // An em dash, not a disabled button: there is nothing here, and offering a dead control would
        // suggest otherwise.
        return '<span class="util-muted">&mdash;</span>';
    }

    $html = '<div class="a2t-slot">';

    if ($slot->hasOriginalAudio) {
        $html .= '<button class="a2t-play" type="button"'
            . ' data-a2t-play="' . Html::encode($originalUrl($slot->jobPublicId)) . '"'
            . ' aria-label="' . Html::encode('Play ' . $slot->label() . ' recording') . '">'
            . '<span class="a2t-play__icon" aria-hidden="true"></span>'
            . '<span class="a2t-play__time">' . Html::encode($clock($slot->durationSeconds)) . '</span>'
            . '</button>';
    } else {
        // Retention took the file, or it was never kept. The transcript is still there, so the row
        // stays useful — it just cannot be listened to.
        $html .= '<span class="a2t-slot__gone" title="This recording is no longer stored on the server">'
            . Html::encode($clock($slot->durationSeconds)) . '</span>';
    }

    if ($slot->isReviewable()) {
        $html .= '<button class="a2t-slot__link" type="button"'
            . ' data-a2t-details="' . Html::encode($fragmentUrl($slot->jobPublicId)) . '"'
            . ' data-a2t-details-full="' . Html::encode($fullReviewUrl($slot->jobPublicId)) . '"'
            . ' data-a2t-details-label="' . Html::encode($slot->label()) . '">Details</button>';
    } elseif ($slot->status->value === 'FAILED') {
        $html .= '<span class="a2t-slot__note">Failed</span>';
    } elseif ($slot->status->value !== 'COMPLETED') {
        $html .= '<span class="a2t-slot__note">Converting…</span>';
    }

    if ($slot->olderCount() > 0) {
        // Nothing is hidden, only folded: every earlier recording keeps its own play, Details and
        // transcript, rendered here and moved into the history dialog when it opens. Rendered rather
        // than fetched because it is three short rows the page already had in hand — an endpoint for
        // it would be a request to learn something this page already knows.
        $html .= '<button class="a2t-slot__more" type="button"'
            . ' data-a2t-history="' . Html::encode($slot->jobPublicId) . '">+'
            . $slot->olderCount() . ' more</button>'
            . '<div class="a2t-history-source" data-a2t-history-for="'
            . Html::encode($slot->jobPublicId) . '" hidden>';

        foreach ($slot->older as $older) {
            $html .= '<div class="a2t-history-row">'
                . '<span class="a2t-history-row__when">'
                . Html::encode($appTimeZone->format($older->uploadedAt, 'M j, Y g:i A'))
                . '</span>'
                . '<span class="a2t-history-row__meta">'
                . Html::encode($older->label() . ' · ' . $older->provider->label()
                    . ' · ' . $older->status->label())
                . '</span>'
                . $slotCellInner($older)
                . '</div>';
        }

        $html .= '</div>';
    }

    return $html . '</div>';
};

/**
 * The generated-audio side of one recording.
 *
 * A play control only when there are bytes to play. Every other state is a word, because a button that
 * cannot do anything is worse than a sentence saying why.
 */
$ttsCell = static function (StoreRecordingSlot $slot) use ($generatedUrl): string {
    // Two grid cells rather than a row wrapper: the whole column is one grid, so every recording's
    // status starts at the same x whatever its label is, and neither half ever wraps mid-phrase.
    $html = '<span class="a2t-tts__label">' . Html::encode($slot->label()) . '</span>';

    if ($slot->hasGeneratedAudio()) {
        return $html
            . '<button class="a2t-play a2t-play--sm" type="button"'
            . ' data-a2t-play="' . Html::encode($generatedUrl($slot->jobPublicId)) . '"'
            . ' aria-label="' . Html::encode('Play generated ' . $slot->label() . ' audio') . '">'
            . '<span class="a2t-play__icon" aria-hidden="true"></span></button>';
    }

    return $html . '<span class="a2t-tts__state">'
        . Html::encode($slot->aiAudioState()->label()) . '</span>';
};
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= Html::encode($store->name) ?></h1>
        <p class="page-header__subtitle">
            Call recordings for this store, grouped by the order they belong to.
        </p>
    </div>
    <div class="page-header__actions">
        <?php
        // A real link, so the dialog is reachable with scripts off: the server answers `?upload=1` by
        // rendering the dialog already open. The script upgrades the same element to `showModal()`.
?>
        <?php if ($canUpload): ?>
            <a class="btn btn--primary" href="<?= Html::encode($storeUrl . '?upload=1') ?>"
               data-a2t-open="a2t-upload-dialog">+ Add Audio</a>
        <?php endif; ?>
        <a class="btn" href="<?= Html::encode($urlGenerator->generate('order58.store-audio')) ?>">All stores</a>
    </div>
</div>

<p class="util-muted util-mono">
    Store #<?= $store->sourceId ?><?php if ($store->company !== null): ?> &middot; <?= Html::encode($store->company) ?><?php endif; ?>
    <?php if (!$store->active): ?> &middot; <span class="badge badge--error">Source inactive</span><?php endif; ?>
</p>

<?php
// Only when something is wrong. An administrator about to queue a recording needs to know when nothing
// is going to pick it up; the full counters strip belongs on the conversions list.
?>
<?php if (!$worker->isHealthy()): ?>
    <div class="alert alert--warning" role="status">
        <p>
            <?= Html::encode($worker->label()) ?><?php if ($worker->detail() !== null): ?> &mdash; <?= Html::encode($worker->detail()) ?><?php endif; ?>
        </p>
        <p>Uploads are still accepted and will be transcribed once the worker is running again.</p>
    </div>
<?php endif; ?>

<?php if (!$canUpload): ?>
    <div class="alert alert--warning" role="status">
        <p>
            Order58 reports this store as inactive, so no new recordings can be uploaded for it.
            Everything already converted for it is listed below.
        </p>
    </div>
<?php endif; ?>

<?php
// Where an in-page action reports itself. The layout renders server-side flashes above this; a
// generation asked for from the dialog never reloads, so it says so here instead — same `.alert`
// shell, so the two read as one thing.
?>
<div class="a2t-notice" data-a2t-notice role="status" aria-live="polite" hidden></div>

<div class="card a2t-wide">
    <h2 class="card__title">This store's conversions</h2>

    <?php if ($groups === []): ?>
        <?php // A short sentence and the one action that changes it, rather than an empty table.?>
        <div class="a2t-empty-state">
            <p>No audio recordings yet.</p>
            <?php if ($canUpload): ?>
                <a class="btn btn--primary" href="<?= Html::encode($storeUrl . '?upload=1') ?>"
                   data-a2t-open="a2t-upload-dialog">+ Add Audio</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="a2t-table-scroll">
            <table class="table a2t-table a2t-orders">
                <?php
                // Sized to content, with the three recording columns sharing the slack: a filename no
                // longer appears here, so nothing in this table has an unbounded length.
        ?>
                <colgroup>
                    <col class="a2t-col-order">
                    <col class="a2t-col-slot">
                    <col class="a2t-col-slot">
                    <col class="a2t-col-slot">
                    <col class="a2t-col-status">
                    <col class="a2t-col-tts">
                    <col class="a2t-col-row-actions">
                </colgroup>
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Mix / Common</th>
                        <th>Caller</th>
                        <th>Callee</th>
                        <th>Status</th>
                        <th>Text to Audio</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($groups as $group): ?>
                    <?php $status = $group->aggregateStatus(); ?>
                    <tr>
                        <td>
                            <?php if ($group->orderId !== null): ?>
                                <span class="a2t-order-id">#<?= Html::encode($group->orderId) ?></span>
                            <?php else: ?>
                                <?php
                        // An upload that named no order is still its own row — never merged
                        // with every other order-less upload. It says so rather than showing
                        // a blank cell that would read as missing data.
                                ?>
                                <span class="util-muted">No order</span>
                            <?php endif; ?>
                            <span class="a2t-order-when">
                                <?= Html::encode($appTimeZone->format($group->latestActivityAt, 'M j, Y g:i A')) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($group->isLegacySeparate()): ?>
                                <?php
                                // A pair uploaded before recording types existed. Its halves are
                                // Customer and Agent — roles the administrator supplied — and this
                                // application has never known which of them called whom, so they are
                                // shown under their own names rather than as Caller and Callee.
                                ?>
                                <div class="a2t-legacy">
                                    <span class="a2t-legacy__tag">Customer + Agent</span>
                                    <?php foreach ($group->legacySeparate as $half): ?>
                                        <?= $slotCell($half) ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <?= $slotCell($group->mixed) ?>
                            <?php endif; ?>
                        </td>
                        <td><?= $slotCell($group->caller) ?></td>
                        <td><?= $slotCell($group->callee) ?></td>
                        <td>
                            <span class="a2t-badge a2t-badge--<?= Html::encode($status->badgeModifier()) ?>">
                                <?= Html::encode($status->label()) ?>
                            </span>
                        </td>
                        <td>
                            <?php $primaries = $group->primaries(); ?>
                            <?php if ($primaries === []): ?>
                                <span class="util-muted">&mdash;</span>
                            <?php else: ?>
                                <div class="a2t-tts-list">
                                    <?php foreach ($primaries as $slot): ?>
                                        <?= $ttsCell($slot) ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="a2t-cell-actions">
                            <?php if ($group->hasAnyTranscript()): ?>
                                <button class="a2t-slot__link" type="button"
                                        data-a2t-transcripts="<?= Html::encode(
                                            $groupUrl(AudioToTextRoute::STORE_GROUP_TRANSCRIPTS, $group->key),
                                        ) ?>"
                                        data-a2t-order="<?= Html::encode($group->orderId ?? '') ?>">
                                    Original transcript
                                </button>
                            <?php endif; ?>
                            <button class="a2t-slot__link" type="button"
                                    data-a2t-tts="<?= Html::encode(
                                        $groupUrl(AudioToTextRoute::STORE_GROUP_TTS_OPTIONS, $group->key),
                                    ) ?>"
                                    data-a2t-order="<?= Html::encode($group->orderId ?? '') ?>">
                                Generate Text to Audio
                            </button>
                            <?php
                            // Offered for every row, including one whose recordings cannot be replaced:
                            // the dialog is also where an administrator reads what an order holds and
                            // why a replacement is refused, and a missing button answers neither.
                    ?>
                            <button class="a2t-slot__link" type="button"
                                    data-a2t-manage="<?= Html::encode(
                                        $groupUrl(AudioToTextRoute::STORE_GROUP_RECORDINGS, $group->key),
                                    ) ?>"
                                    data-a2t-order="<?= Html::encode($group->orderId ?? '') ?>">
                                Manage Audio
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pageCount > 1): ?>
            <?= $this->render(dirname(__DIR__, 4) . '/Web/Shared/_partial/pager', [
                'page' => $page,
                'pageCount' => $pageCount,
                'pageUrl' => $pageUrl,
            ]) ?>
        <?php endif; ?>

        <p class="util-muted">
            <?= $total ?> order<?= $total === 1 ? '' : 's' ?> for this store, newest first.
            Every recording of one order shares its row.
        </p>
    <?php endif; ?>
</div>

<p class="util-muted">
    <?php if ($retentionHours === null): ?>
        Conversions and their recordings are kept on this server indefinitely.
    <?php else: ?>
        Conversions and their recordings are kept for <?= $retentionHours ?> hours, then removed.
    <?php endif; ?>
</p>

<?php // ---- The dialogs -------------------------------------------------------------------------?>
<?php if ($canUpload): ?>
    <?php
    // ONE form, where there were three. The recording type it posts is a radio rather than three
    // hidden inputs; everything else — the field names, the CSRF token, the progress hooks the upload
    // script binds to — is exactly what the cards posted, because the pipeline behind it is unchanged.
    //
    // `.a2t-uploads` is load-bearing: the upload script scrapes `.a2t-uploads .field__error` to report
    // a server-side refusal, so a field error outside that ancestor would silently become a generic
    // "could not confirm this upload".
    ?>
    <dialog class="source-modal a2t-upload-dialog" id="a2t-upload-dialog" data-a2t-dialog
            aria-labelledby="a2t-upload-title"<?= $uploadOpen ? ' open' : '' ?>>
        <div class="source-modal__head">
            <h2 class="source-modal__title" id="a2t-upload-title">Add audio</h2>
            <button class="source-modal__close" type="button" data-a2t-dialog-close
                title="Close" aria-label="Close">&times;</button>
        </div>
        <div class="source-modal__body a2t-uploads">
            <?php if ($formErrors !== []): ?>
                <div class="alert alert--error" role="alert">
                    <?php foreach ($formErrors as $error): ?>
                        <p><?= Html::encode($error) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form class="a2t-upload-form" data-a2t-upload data-a2t-stay id="a2t-upload-form"
                  method="post" action="<?= Html::encode($storeUrl) ?>" enctype="multipart/form-data">
                <?= $csrfField ?>
                <input type="hidden" name="mode" value="<?= Html::encode(ConversationMode::Common->value) ?>">

                <div class="field">
                    <label class="field__label" for="a2t-audio">Audio file</label>
                    <?php
                    // A <label> wrapping the input, not a scripted drop zone: the CSP forbids inline
                    // script and the upload has to keep working with scripts off, which is exactly the
                    // path where a scripted picker would be dead.
    ?>
                    <label class="a2t-upload-picker" for="a2t-audio">
                        <svg class="a2t-upload-picker__icon" aria-hidden="true" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 16V3m-4 4 4-4 4 4M4 15v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4"/>
                        </svg>
                        <span class="a2t-upload-picker__title" aria-hidden="true">Choose an audio file</span>
                        <span class="a2t-upload-picker__hint" aria-hidden="true">Browse files to get started</span>
                        <input class="a2t-upload-input<?= isset($errors['audio']) ? ' field__control--error' : '' ?>"
                               id="a2t-audio" type="file" name="audio"
                               accept=".wav,.mp3,.m4a,.ogg,.webm,audio/*" aria-describedby="a2t-audio-limits">
                    </label>
                    <div class="a2t-upload-file" data-a2t-file hidden></div>
                    <?= $fieldErrors($errors['audio'] ?? []) ?>
                    <div class="field__hint" id="a2t-audio-limits">
                        <?= Html::encode($extensionList) ?><br>
                        Up to <?= Html::encode($maxUploadLabel) ?> &middot; <?= Html::encode($maxDurationLabel) ?> maximum
                    </div>
                </div>

                <?= $orderIdField('a2t-order-id') ?>

                <div class="field">
                    <span class="field__label">Recording type</span>
                    <?php
    // The one thing three cards said that one form has to say some other way. The
    // server allow-lists whatever arrives, so this is a convenience, not the control.
    ?>
                    <div class="a2t-type-choice">
                        <?php foreach (RecordingType::cases() as $type): ?>
                            <label class="a2t-checkbox" for="a2t-type-<?= Html::encode(strtolower($type->value)) ?>">
                                <input type="radio" name="recording_type"
                                       id="a2t-type-<?= Html::encode(strtolower($type->value)) ?>"
                                       value="<?= Html::encode($type->value) ?>"
                                    <?= $type === $selectedType ? 'checked' : '' ?>>
                                <span><?= Html::encode($type->label()) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="field__hint">
                        Which side of the call this file holds. Speakers are still worked out on this
                        server for a mixed recording.
                    </div>
                </div>

                <?= $providerField('a2t-provider') ?>
                <?= $aiAudioField('a2t-ai-audio') ?>

                <div class="a2t-upload-feedback" data-a2t-feedback data-a2t-state="idle" hidden>
                    <div class="a2t-upload-feedback__heading">
                        <strong>Recording progress</strong>
                        <span class="a2t-upload-state" data-a2t-state-label>Ready</span>
                    </div>
                    <div class="a2t-upload-step" data-a2t-step="upload" data-state="pending">
                        <div class="a2t-upload-feedback__label">
                            <span class="a2t-upload-step__title"><span aria-hidden="true">01</span> Upload</span>
                            <span data-a2t-upload-percent aria-hidden="true">0%</span>
                        </div>
                        <progress class="a2t-upload-progress" data-a2t-upload-progress max="100" value="0"
                                  aria-label="Upload progress"></progress>
                        <p class="a2t-upload-step__detail" data-a2t-upload-status role="status">Awaiting audio file</p>
                    </div>
                    <div class="a2t-upload-step" data-a2t-step="conversion" data-state="pending">
                        <div class="a2t-upload-feedback__label">
                            <span class="a2t-upload-step__title"><span aria-hidden="true">02</span> Conversion</span>
                            <span data-a2t-conversion-percent aria-hidden="true">Pending</span>
                        </div>
                        <progress class="a2t-upload-progress" data-a2t-conversion-progress max="100" value="0"
                                  aria-label="Conversion progress"></progress>
                        <p class="a2t-upload-step__detail" data-a2t-conversion-status role="status">Starts after upload</p>
                    </div>
                    <p class="a2t-upload-error" data-a2t-upload-error role="alert" hidden></p>
                    <a class="a2t-upload-result" data-a2t-upload-result hidden>View conversion <span aria-hidden="true">&#8599;</span></a>
                </div>

                <button class="btn btn--primary a2t-upload-submit" type="submit">Upload &amp; Transcribe</button>
            </form>
        </div>
    </dialog>
<?php endif; ?>

<?php
// The three read/edit dialogs are rendered empty and filled on demand: a store page must not carry
// every transcript of every row it lists. Each one follows the shell the chat source dialog
// established — a `<dialog>` with data hooks, no ids printed, closed by its own button or a backdrop
// click, and filled with textContent rather than markup.
?>
<dialog class="source-modal a2t-review-dialog" id="a2t-review-dialog" data-a2t-dialog
        aria-labelledby="a2t-review-title">
    <div class="source-modal__head">
        <div>
            <h2 class="source-modal__title" id="a2t-review-title" data-a2t-review-title>Recording details</h2>
            <p class="source-modal__meta" data-a2t-review-meta></p>
        </div>
        <div class="a2t-dialog__actions">
            <?php
            // Drag-to-move and select-to-merge measure the review page's own scroll container and
            // cannot work in a dialog, so they stay where they work and this is the way to them.
?>
            <a class="btn btn--sm" data-a2t-full-editor href="#">Open full editor</a>
            <button class="source-modal__close" type="button" data-a2t-dialog-close
                title="Close" aria-label="Close">&times;</button>
        </div>
    </div>
    <p class="source-modal__status" data-a2t-review-status hidden></p>
    <?php
    // The token this dialog's corrections are sent with. A rendered input, exactly as every form on
    // this page uses, read by the script and sent as `X-CSRF-Token` — the header the existing
    // middleware already accepts. Nothing about the token is composed in JavaScript.
?>
    <div class="a2t-review-token" data-a2t-review-token hidden><?= $csrfField ?></div>
    <?php
    // The three icons the correction page puts beside a bubble: the handle, the pencil, and the clock
    // on a message that has been corrected before. Rendered once and cloned per turn, from the same
    // constants that page draws, so no SVG path is written out a second time in JavaScript.
    //
    // Each label is left empty and filled per turn: "Move to Customer" and "Move to Agent" are the
    // same icon saying two different things, and which one it says is the server's decision.
?>
    <div class="a2t-iconbank" data-a2t-iconbank hidden>
        <template data-a2t-icon="move"><?= AudioToTextIcons::svg(AudioToTextIcons::GRIP, '') ?></template>
        <template data-a2t-icon="edit"><?= AudioToTextIcons::svg(AudioToTextIcons::PENCIL, '') ?></template>
        <template data-a2t-icon="history"><?= AudioToTextIcons::svg(AudioToTextIcons::CLOCK, '') ?></template>
        <?php
        // And the transport for the browser's own voice, from the same constants for the same reason.
?>
        <template data-a2t-icon="play"><?= AudioToTextIcons::svg(AudioToTextIcons::PLAY, '') ?></template>
        <template data-a2t-icon="pause"><?= AudioToTextIcons::svg(AudioToTextIcons::PAUSE, '') ?></template>
        <template data-a2t-icon="resume"><?= AudioToTextIcons::svg(AudioToTextIcons::RESUME, '') ?></template>
        <template data-a2t-icon="stop"><?= AudioToTextIcons::svg(AudioToTextIcons::STOP, '') ?></template>
    </div>
    <?php
    // Where the revision dialogs land. Fetched from the same partial the correction page renders
    // inline, after every read, so a message corrected a moment ago has its clock icon and its
    // revision without the reader reloading anything.
?>
    <div class="a2t-history-dialogs" data-a2t-history-host></div>
    <?php
    // `a2t-review` is the correction page's own scope, borrowed deliberately: it is what stacks the
    // controls under a bubble, puts the icons in the margin on the speaker's side and tints a
    // published Agent turn green. `a2t-chat` is **not** borrowed — `.app:has(.a2t-chat)` pins the
    // whole shell to 100vh, which is right for a page that is only a conversation and wrong for a
    // table with a dialog over it.
?>
    <div class="source-modal__body a2t-review" data-a2t-review-body hidden>
        <?php
        // Three ways to hear this recording, filled per recording when the dialog opens: the file that
        // was uploaded, the audio this application generated from the transcript, and the browser
        // reading the transcript aloud itself. They are kept apart because they are different things —
        // one is evidence, one costs money, and one is a convenience that leaves nothing behind.
?>
        <div class="a2t-listen" data-a2t-listen hidden></div>
        <div class="a2t-mnotice" data-a2t-review-notice></div>
        <div class="a2t-chat__scroll a2t-dialog-scroll" data-a2t-review-scroll></div>
    </div>
</dialog>

<dialog class="source-modal a2t-transcript-dialog" id="a2t-transcript-dialog" data-a2t-dialog
        aria-labelledby="a2t-transcript-title">
    <div class="source-modal__head">
        <div>
            <h2 class="source-modal__title" id="a2t-transcript-title">Original transcript</h2>
            <p class="source-modal__meta" data-a2t-transcript-meta></p>
        </div>
        <button class="source-modal__close" type="button" data-a2t-dialog-close
                title="Close" aria-label="Close">&times;</button>
    </div>
    <div class="a2t-tabs" role="tablist" data-a2t-transcript-tabs hidden></div>
    <p class="source-modal__status" data-a2t-transcript-status hidden></p>
    <?php
    // The same bubbles, without `a2t-review`: nothing here can be corrected, so there is no margin to
    // reserve for controls and no role to tint. That is the read-only conversation page's own layout.
?>
    <div class="source-modal__body" data-a2t-transcript-body hidden>
        <div class="a2t-chat__scroll a2t-dialog-scroll" data-a2t-transcript-scroll></div>
    </div>
</dialog>

<?php
// Manage Audio. One section per kind of recording, each listing its current version and everything it
// superseded, with the replacement upload inside the section it belongs to.
//
// The form is a real multipart POST carrying the page's CSRF field, submitted by `fetch` so the reader
// stays on the store page — the same shape the Generate form uses. Nothing about which recording is
// being replaced is decided here: the section supplies the recording type, and the server re-checks it
// against the group it resolved.
?>
<dialog class="source-modal a2t-manage-dialog" id="a2t-manage-dialog" data-a2t-dialog
        aria-labelledby="a2t-manage-title">
    <div class="source-modal__head">
        <div>
            <h2 class="source-modal__title" id="a2t-manage-title">Manage Audio</h2>
            <p class="source-modal__meta" data-a2t-manage-meta></p>
        </div>
        <button class="source-modal__close" type="button" data-a2t-dialog-close
                title="Close" aria-label="Close">&times;</button>
    </div>
    <p class="source-modal__status" data-a2t-manage-status hidden></p>
    <div class="source-modal__body" data-a2t-manage-body hidden>
        <div class="a2t-manage" data-a2t-manage-slots></div>
    </div>
    <div class="a2t-manage-token" data-a2t-manage-token hidden><?= $csrfField ?></div>
</dialog>

<dialog class="source-modal a2t-tts-dialog" id="a2t-tts-dialog" data-a2t-dialog
        aria-labelledby="a2t-tts-title">
    <div class="source-modal__head">
        <div>
            <h2 class="source-modal__title" id="a2t-tts-title">Generate Text to Audio</h2>
            <p class="source-modal__meta" data-a2t-tts-meta></p>
        </div>
        <button class="source-modal__close" type="button" data-a2t-dialog-close
                title="Close" aria-label="Close">&times;</button>
    </div>
    <p class="source-modal__status" data-a2t-tts-status hidden></p>
    <?php
// A real form posting to the existing generate route. The action, the output type and the hash all
// come from the server's own answer about this group — the browser never decides which job or
// which output type a choice means, because the UI's Caller is not the column's CUSTOMER.
?>
    <form class="source-modal__body" method="post" action="" data-a2t-tts-form hidden>
        <?= $csrfField ?>
        <input type="hidden" name="output_type" value="" data-a2t-tts-output>
        <input type="hidden" name="expected_hash" value="" data-a2t-tts-hash>
        <div class="a2t-tts-options" data-a2t-tts-options></div>
        <button class="btn btn--primary" type="submit" data-a2t-tts-submit disabled>Generate</button>
    </form>
</dialog>

<?php
// The same two confirmations the correction page shows, from the same partial. The dialog submits
// them by fetch instead of following the redirect; the fields, the token and the version are
// identical, which is what makes this the same operation rather than a second one that resembles it.
?>
<?= $this->render(AudioToTextViews::reviewConfirm(), [
    'csrfField' => $csrfField,
    'version' => null,
]) ?>

<dialog class="source-modal a2t-history-dialog" id="a2t-history-dialog" data-a2t-dialog
        aria-labelledby="a2t-history-title">
    <div class="source-modal__head">
        <h2 class="source-modal__title" id="a2t-history-title">Earlier recordings</h2>
        <button class="source-modal__close" type="button" data-a2t-dialog-close
                title="Close" aria-label="Close">&times;</button>
    </div>
    <div class="source-modal__body" data-a2t-history-body></div>
</dialog>
