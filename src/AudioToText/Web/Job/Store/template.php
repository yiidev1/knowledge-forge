<?php

declare(strict_types=1);

use App\AudioToText\Domain\AudioConversation;
use App\AudioToText\Domain\AudioConversationChild;
use App\AudioToText\Domain\AudioStore;
use App\AudioToText\Domain\ConversationMode;
use App\AudioToText\Domain\JobStatus;
use App\AudioToText\Domain\TranscriptionProvider;
use App\AudioToText\Domain\WorkerStatusView;
use App\AudioToText\Web\AudioToTextRoute;
use App\Shared\Application\Time\AppTimeZone;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\Csrf;

/**
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var Csrf $csrf
 * @var AudioStore $store
 * @var ConversationMode $mode
 * @var array<string, list<string>> $errors
 * @var list<AudioConversation> $conversations
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
 * @var TranscriptionProvider $provider currently selected, and what both forms preselect
 * @var list<TranscriptionProvider> $providerChoices every provider, usable or not
 * @var array<string, bool> $providerUsable storage value => can this server run it (local check only)
 * @var TranscriptionProvider $globalDefault the stored setting, reported as-is and never rewritten here
 * @var bool $ttsConfigured whether clean AI audio can be generated on this server at all
 */

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
 * The provider select, rendered into both forms.
 *
 * A closure rather than two copies because the two forms post to the same action and must offer
 * exactly the same choices — a difference between them would be a bug nobody would see until an
 * upload came back with the wrong engine. The id differs per form so each `<label for>` stays valid
 * on a page that renders both.
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
 * The AI-audio opt-in, rendered into both forms.
 *
 * A closure for the same reason the provider select is one: the two forms post to the same action and
 * must offer exactly the same choice. The id differs per form so each `<label for>` stays valid on a
 * page that renders both.
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

$formErrors = $errors['form'] ?? [];
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= Html::encode($store->name) ?></h1>
        <?php
        // Provider-neutral wording. This used to promise that nothing left the server, which stopped
        // being true the moment Deepgram became selectable — and a false privacy claim is worse than
        // no claim. What each upload actually uses is stated on the field that decides it.
?>
        <p class="page-header__subtitle">
            Upload call recordings for this store. New uploads use the configured default transcription
            provider unless you choose another one.
        </p>
    </div>
    <a class="btn" href="<?= Html::encode($urlGenerator->generate('order58.store-audio')) ?>">All stores</a>
</div>

<p class="util-muted util-mono">
    Store #<?= $store->sourceId ?><?php if ($store->company !== null): ?> · <?= Html::encode($store->company) ?><?php endif; ?>
    <?php if (!$store->active): ?> · <span class="badge badge--error">Source inactive</span><?php endif; ?>
</p>

<?php
// Only when something is wrong. The full counters-and-worker strip belongs on the conversions list,
// which is the technical view of the queue; here it would compete with the store's own history for
// attention every time an administrator came to upload a file. But an administrator about to queue a
// recording does need to know when nothing is going to pick it up.
?>
<?php if (!$worker->isHealthy()): ?>
    <div class="alert alert--warning" role="status">
        <p>
            <?= Html::encode($worker->label()) ?><?php if ($worker->detail() !== null): ?> — <?= Html::encode($worker->detail()) ?><?php endif; ?>
        </p>
        <p>Uploads are still accepted and will be transcribed once the worker is running again.</p>
    </div>
<?php endif; ?>

<?php if ($formErrors !== []): ?>
    <div class="alert alert--error" role="alert">
        <?php foreach ($formErrors as $error): ?>
            <p><?= Html::encode($error) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
// Readable but not writable. The history has to stay reachable — the global conversions list links
// straight here — so an inactive store keeps its page and loses only the upload. The server refuses
// the POST regardless of what this template renders.
//
// When it is writable there are two forms, not one form with a JavaScript mode switch: this
// application's content security policy is `script-src 'self'` with no inline JavaScript, and a
// toggle that hides half a form is the kind of thing that quietly submits the wrong fields when the
// script does not run. Each form carries its own mode, so what the administrator sees is exactly
// what is posted.
?>
<?php if (!$canUpload): ?>
    <div class="alert alert--warning" role="status">
        <p>
            Order58 reports this store as inactive, so no new recordings can be uploaded for it.
            Everything already converted for it is listed below.
        </p>
    </div>
<?php else: ?>
<div class="card">
    <h2 class="card__title">One mixed recording</h2>
    <p class="field__hint" style="margin-top: -0.5rem;">
        A single file containing both people. The server works out who is speaking, and you can correct
        it afterwards.
    </p>

    <form id="a2t-common-form" method="post" action="<?= Html::encode($storeUrl) ?>" enctype="multipart/form-data" style="max-width: 720px;">
        <?= $csrfField ?>
        <input type="hidden" name="mode" value="<?= Html::encode(ConversationMode::Common->value) ?>">

        <div class="field">
            <label class="field__label" for="a2t-audio">Audio file</label>
            <input
                class="field__control<?= isset($errors['audio']) ? ' field__control--error' : '' ?>"
                id="a2t-audio"
                type="file"
                name="audio"
                accept=".wav,.mp3,.m4a,.ogg,.webm,audio/*"
            >
            <?= $fieldErrors($errors['audio'] ?? []) ?>
            <div class="field__hint">
                <?= Html::encode($extensionList) ?> ·
                up to <?= Html::encode($maxUploadLabel) ?> ·
                up to <?= Html::encode($maxDurationLabel) ?> long.
            </div>
        </div>

        <?= $providerField('a2t-common-provider') ?>
        <?= $aiAudioField('a2t-common-ai-audio') ?>

        <button class="btn btn--primary" type="submit">Convert mixed recording</button>
    </form>
</div>

<div class="card">
    <h2 class="card__title">Separate Customer and Agent recordings</h2>
    <p class="field__hint" style="margin-top: -0.5rem;">
        Two files from one call, one per person. Because you are telling us who is on each recording,
        the server does not try to work it out — so there are no speakers to correct afterwards.
    </p>

    <form id="a2t-separate-form" method="post" action="<?= Html::encode($storeUrl) ?>" enctype="multipart/form-data" style="max-width: 720px;">
        <?= $csrfField ?>
        <input type="hidden" name="mode" value="<?= Html::encode(ConversationMode::Separate->value) ?>">

        <div class="field">
            <label class="field__label" for="a2t-customer-audio">Customer audio</label>
            <input
                class="field__control<?= isset($errors['customer_audio']) ? ' field__control--error' : '' ?>"
                id="a2t-customer-audio"
                type="file"
                name="customer_audio"
                accept=".wav,.mp3,.m4a,.ogg,.webm,audio/*"
            >
            <?= $fieldErrors($errors['customer_audio'] ?? []) ?>
        </div>

        <div class="field">
            <label class="field__label" for="a2t-agent-audio">Agent audio</label>
            <input
                class="field__control<?= isset($errors['agent_audio']) ? ' field__control--error' : '' ?>"
                id="a2t-agent-audio"
                type="file"
                name="agent_audio"
                accept=".wav,.mp3,.m4a,.ogg,.webm,audio/*"
            >
            <?= $fieldErrors($errors['agent_audio'] ?? []) ?>
            <div class="field__hint">
                <?= Html::encode($extensionList) ?> ·
                up to <?= Html::encode($maxUploadLabel) ?> each and
                <?= Html::encode($combinedLimitLabel) ?> for the two together ·
                up to <?= Html::encode($maxDurationLabel) ?> long each.
                Both recordings are queued separately and may finish at different times.
            </div>
        </div>

        <?= $providerField('a2t-separate-provider') ?>
        <?= $aiAudioField('a2t-separate-ai-audio') ?>

        <button class="btn btn--primary" type="submit">Convert both recordings</button>
    </form>
</div>
<?php endif; ?>

<div class="card a2t-wide">
    <h2 class="card__title">This store's conversions</h2>

    <?php if ($conversations === []): ?>
        <p class="util-muted">Nothing uploaded for this store yet.</p>
    <?php else: ?>
        <div class="a2t-table-scroll">
            <table class="table a2t-table">
                <thead>
                    <tr>
                        <th>Recordings</th>
                        <th>Type</th>
                        <th>Uploaded at</th>
                        <th>Status</th>
                        <th>Duration</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($conversations as $conversation): ?>
                    <?php
                    $status = $conversation->status();
                    $separate = $conversation->mode === ConversationMode::Separate;
                    $viewUrl = $urlGenerator->generate(
                        AudioToTextRoute::CONVERSION,
                        ['publicId' => $conversation->publicId],
                    );
                    $duration = $conversation->totalDurationSeconds();
                    ?>
                    <tr>
                        <td class="a2t-cell-file">
                            <?php foreach ($conversation->children as $child): ?>
                                <?php /** @var AudioConversationChild $child */ ?>
                                <div title="<?= Html::encode($child->originalFilename) ?>">
                                    <?php if ($separate): ?>
                                        <strong><?= Html::encode($child->sourceRole->label()) ?>:</strong>
                                    <?php endif; ?>
                                    <?= Html::encode($child->originalFilename) ?>
                                </div>
                            <?php endforeach; ?>
                        </td>
                        <td><?= Html::encode($conversation->mode->label()) ?></td>
                        <td><?= Html::encode($appTimeZone->format($conversation->createdAt, 'M j, Y g:i A')) ?></td>
                        <td>
                            <span class="a2t-badge a2t-badge--<?= Html::encode($status->badgeModifier()) ?>">
                                <?= Html::encode($status->label()) ?>
                            </span>
                            <?php foreach ($conversation->children as $child): ?>
                                <?php if ($child->errorMessage !== null): ?>
                                    <div class="a2t-cell-note">
                                        <?php if ($separate): ?>
                                            <?= Html::encode($child->sourceRole->label()) ?>:
                                        <?php endif; ?>
                                        <?= Html::encode($child->errorMessage) ?>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </td>
                        <td>
                            <?= $duration === null ? '—' : Html::encode(number_format($duration, 1) . 's') ?>
                        </td>
                        <td class="a2t-cell-actions">
                            <a href="<?= Html::encode($viewUrl) ?>">View</a>
                            <?php
                            // The machine's own transcript, offered only where there is one to show.
                            // A separate Customer + Agent conversion stores no segments at all — the
                            // roles were supplied, so nothing was diarized — and an unfinished job has
                            // not produced one yet. Offering a link that could only redirect would be
                            // worse than not offering it.
                            $original = !$separate ? $conversation->singleChild() : null;
                    ?>
                            <?php if ($original !== null && $original->status === JobStatus::COMPLETED): ?>
                                <a href="<?= Html::encode($urlGenerator->generate(
                                    AudioToTextRoute::JOB_ORIGINAL,
                                    ['publicId' => $original->publicId],
                                )) ?>">Original transcript</a>
                            <?php endif; ?>
                            <?php
                                        // One compact link rather than a column of its own — the page behind it
                                        // explains the state, and a status badge here would compete with the one
                                        // that already says whether the conversion finished.
                                        //
                                        // Offered once anything has been transcribed. It is deliberately NOT
                                        // conditional on whether audio exists: "not generated yet" is one of the
                                        // things that page is for, and hiding the link until after the fact would
                                        // leave no way to reach the button that generates it.
                                        $anyCompleted = false;
                    foreach ($conversation->children as $child) {
                        if ($child->status === JobStatus::COMPLETED) {
                            $anyCompleted = true;

                            break;
                        }
                    }
                    ?>
                            <?php if ($anyCompleted): ?>
                                <a href="<?= Html::encode($urlGenerator->generate(
                                    AudioToTextRoute::CONVERSION_AI_AUDIO,
                                    ['publicId' => $conversation->publicId],
                                )) ?>">AI audio</a>
                            <?php endif; ?>
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
            <?= $total ?> conversion<?= $total === 1 ? '' : 's' ?> for this store, newest first.
            A separate Customer and Agent upload counts as one.
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
