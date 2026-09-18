<?php

declare(strict_types=1);

use App\AudioToText\Domain\Tts\AiAudioState;
use App\AudioToText\Web\AudioToTextRoute;
use App\AudioToText\Web\Conversion\AiAudio\AiAudioPage;
use App\AudioToText\Web\Conversion\AiAudio\AiAudioRow;
use App\Shared\Application\Time\AppTimeZone;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\Csrf;

/**
 * Clean AI training audio for one call.
 *
 * Decides nothing. State, staleness, what may be generated and why it may not are all settled by
 * {@see AiAudioPage} before they arrive here — a template working out whether audio was stale would be
 * a second implementation of the rule that decides whether to spend money.
 *
 * No JavaScript: `<audio controls>` is native and both buttons are ordinary CSRF-protected forms, so the
 * `script-src 'self'` policy is simply not a consideration.
 *
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var Csrf $csrf
 * @var AiAudioPage $page
 * @var AppTimeZone $appTimeZone
 */

$this->setTitle('AI training audio');

$conversation = $page->conversation;
$store = $page->store;

$this->setParameter('breadcrumbs', array_values(array_filter([
    ['label' => 'Audio to Text', 'route' => AudioToTextRoute::PAGE],
    $store === null ? null : [
        'label' => $store->name,
        'route' => AudioToTextRoute::STORE,
        'arguments' => ['sourceId' => $store->sourceId],
    ],
    ['label' => 'AI audio'],
])));

$localTime = static fn(?DateTimeImmutable $at): string => $at === null
    ? '—'
    : $appTimeZone->format($at, 'M j, Y g:i A');

$fileSize = static function (?int $bytes): string {
    if ($bytes === null || $bytes <= 0) {
        return '—';
    }

    return $bytes < 1048576
        ? number_format($bytes / 1024, 0) . ' KB'
        : number_format($bytes / 1048576, 1) . ' MB';
};

$conversionUrl = $urlGenerator->generate(AudioToTextRoute::CONVERSION, ['publicId' => $conversation->publicId]);
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title">AI training audio</h1>
        <p class="page-header__subtitle">
            A clean synthetic reading of this call's transcript, for agents who cannot follow the
            original recording. It does not replace the original audio.
        </p>
    </div>
    <a class="btn" href="<?= Html::encode($conversionUrl) ?>">Back to conversion</a>
</div>

<?php if (!$page->providerConfigured): ?>
    <div class="alert alert--warning" role="status">
        <p>AI audio is not configured on this server yet, so nothing can be generated.</p>
        <?php
        // The variable names only. An administrator is the person who would fix these, and naming the
        // setting is what makes that possible — but no value is printed, here or anywhere.
    ?>
        <?php foreach ($page->providerProblems as $problem): ?>
            <p class="util-mono"><?= Html::encode($problem) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card">
    <h2 class="card__title">Call information</h2>

    <dl class="a2t-meta">
        <div><dt>Store</dt><dd><?= Html::encode($store->name ?? 'Not associated with a store') ?></dd></div>
        <div><dt>Recording type</dt><dd><?= Html::encode($conversation->mode->label()) ?></dd></div>
        <div><dt>Uploaded by</dt><dd><?= Html::encode($conversation->uploadedByUsername ?? '—') ?></dd></div>
        <div><dt>Uploaded at</dt><dd><?= Html::encode($localTime($conversation->createdAt)) ?></dd></div>
        <div>
            <dt>Original duration</dt>
            <dd><?php $total = $conversation->totalDurationSeconds(); ?>
                <?= $total === null ? '—' : Html::encode(number_format($total, 1) . 's') ?></dd>
        </div>
        <div>
            <dt>Requested at upload</dt>
            <dd><?= $conversation->generateAiAudio ? 'Yes' : 'No' ?></dd>
        </div>
    </dl>

    <p class="util-muted">
        <?php
    // Said out loud because it is the promise the whole feature rests on, and because an agent
    // listening to a clean voice has no way to tell which transcript it came from.
?>
        The audio below is read from this call's transcript exactly as it stands, including every
        correction an administrator has saved. Nothing is summarised, reworded or re-priced.
    </p>
</div>

<div class="card">
    <h2 class="card__title">Transcript source</h2>

    <?php foreach ($page->transcripts as $jobId => $source): ?>
        <?php
$sideLabel = null;
        foreach ($page->rows as $candidate) {
            if ($candidate->job->id === $jobId) {
                $sideLabel = $candidate->sourceLabel();

                break;
            }
        }
        ?>
        <?php if ($sideLabel !== null): ?>
            <h3 class="a2t-subheading"><?= Html::encode($sideLabel) ?></h3>
        <?php endif; ?>
        <dl class="a2t-meta">
            <div><dt>Using</dt><dd><?= Html::encode($source->label()) ?></dd></div>
            <div><dt>Revision</dt><dd><?= $source->revision ?></dd></div>
            <div><dt>Last edited</dt><dd><?= Html::encode($localTime($source->lastEditedAt)) ?></dd></div>
            <div><dt>Edited by</dt><dd><?= Html::encode($source->lastEditedBy ?? '—') ?></dd></div>
            <div><dt>Transcript fingerprint</dt><dd class="util-mono"><?= Html::encode($source->shortHash()) ?></dd></div>
        </dl>
    <?php endforeach; ?>

    <p class="util-muted">
        The fingerprint identifies the exact words the audio was made from. When the transcript changes,
        it changes too — which is how this page knows an existing recording has gone out of date.
    </p>
</div>

<?php if ($page->needsSpeakerConfirmation()): ?>
    <?php
    // Names the fact, names the remedy, links to it — rather than an unexplained disabled button. The
    // rule behind it is only interesting once; the screen where the work happens is what is useful.
    ?>
    <div class="alert alert--warning" role="status">
        <p>
            Agent and Customer have not been confirmed for this call, and a synthetic voice would assert
            a speaker this page does not.
        </p>
        <?php
        $blocked = null;
    foreach ($page->rows as $candidate) {
        if ($candidate->state === AiAudioState::Blocked) {
            $blocked = $candidate;

            break;
        }
    }
    ?>
        <?php if ($blocked !== null && $blocked->job->status->value === 'COMPLETED'): ?>
            <p>
                <a href="<?= Html::encode($urlGenerator->generate(
                    AudioToTextRoute::JOB_REVIEW,
                    ['publicId' => $blocked->job->publicId],
                )) ?>">Confirm the speakers</a>,
                and the audio you asked for at upload will be generated automatically.
            </p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php foreach ($page->rows as $row): ?>
    <?php /** @var AiAudioRow $row */ ?>
    <?php
    $rendition = $row->rendition;
    $fileUrl = $urlGenerator->generate(
        AudioToTextRoute::JOB_AI_AUDIO_FILE,
        ['publicId' => $row->job->publicId],
    );
    ?>
    <div class="card">
        <div class="a2t-job__header">
            <h2 class="card__title"><?= Html::encode($row->title()) ?></h2>
            <span class="a2t-badge a2t-badge--<?= Html::encode($row->state->badgeModifier()) ?>">
                <?= Html::encode($row->state->label()) ?>
            </span>
        </div>

        <?php if ($row->sourceLabel() !== null): ?>
            <p class="util-muted util-mono"><?= Html::encode($row->sourceLabel()) ?></p>
        <?php endif; ?>

        <?php if ($row->state === AiAudioState::Stale): ?>
            <div class="alert alert--warning" role="status">
                <p>Transcript changed after this audio was generated.</p>
                <p>
                    The recording below is still the previous version and still plays. It is not
                    regenerated automatically, because generating costs money.
                </p>
            </div>
        <?php endif; ?>

        <?php if ($row->state === AiAudioState::DifferentVoice): ?>
            <p class="util-muted">
                This was generated with a different voice setting than the one configured now. The words
                are unchanged.
            </p>
        <?php endif; ?>

        <?php if ($row->state === AiAudioState::Failed && $rendition !== null && $rendition->errorMessage !== null): ?>
            <div class="alert alert--error" role="alert">
                <?php
                // Already redacted on the way into the column: a response body is written by a third
                // party, and this one is rendered on a page.
            ?>
                <p><?= Html::encode($rendition->errorMessage) ?></p>
                <?php if ($row->isPlayable()): ?>
                    <p>The previous recording is still available below.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($row->blockedReason !== null): ?>
            <p class="util-muted"><?= Html::encode($row->blockedReason) ?></p>
        <?php endif; ?>

        <?php if ($row->isPlayable()): ?>
            <div class="a2t-player">
                <?php
            // The first and only <audio> element in this application. `preload="none"` because a
            // page with two of them would otherwise fetch several megabytes nobody asked to hear.
            ?>
                <audio class="a2t-player__control" controls preload="none" src="<?= Html::encode($fileUrl) ?>"></audio>
                <p class="util-muted">
                    <strong>AI generated training audio</strong> — not the original recording.
                </p>
            </div>

            <dl class="a2t-meta">
                <div><dt>Provider</dt><dd><?= Html::encode($rendition?->provider ?? '—') ?></dd></div>
                <div>
                    <dt>Voice<?= $row->outputType->isSingleRole() ? '' : 's' ?></dt>
                    <dd class="util-mono">
                        <?php
                    $voices = array_values(array_filter([
                        $rendition?->modelCustomer,
                        $rendition?->modelAgent,
                    ]));
            ?>
                        <?= $voices === [] ? '—' : Html::encode(implode(', ', $voices)) ?>
                    </dd>
                </div>
                <div><dt>Generated</dt><dd><?= Html::encode($localTime($rendition?->generatedAt)) ?></dd></div>
                <div><dt>Size</dt><dd><?= Html::encode($fileSize($rendition?->fileBytes)) ?></dd></div>
                <div><dt>Characters read</dt><dd><?= number_format($rendition?->characterCount ?? 0) ?></dd></div>
                <div><dt>Requested by</dt><dd><?= Html::encode($rendition?->requestedByUsername ?? 'Automatically') ?></dd></div>
            </dl>

            <p>
                <a class="btn" href="<?= Html::encode($fileUrl . '?download=1') ?>">Download</a>
            </p>
        <?php elseif ($row->state !== AiAudioState::Blocked && $row->state !== AiAudioState::Unavailable): ?>
            <p class="util-muted">No audio has been generated for this yet.</p>
        <?php endif; ?>

        <?php if ($row->omittedTurns > 0): ?>
            <p class="util-muted">
                <?= $row->omittedTurns ?>
                turn<?= $row->omittedTurns === 1 ? ' was' : 's were' ?> left out because
                <?= $row->omittedTurns === 1 ? 'it was' : 'they were' ?> not attributed to the Agent or
                the Customer, and there is no voice that could speak
                <?= $row->omittedTurns === 1 ? 'it' : 'them' ?> honestly. Reassign
                <?= $row->omittedTurns === 1 ? 'it' : 'them' ?> on the correction screen if
                <?= $row->omittedTurns === 1 ? 'it belongs' : 'they belong' ?> in the call.
            </p>
        <?php endif; ?>

        <?php if ($row->canGenerate): ?>
            <form method="post" action="<?= Html::encode($urlGenerator->generate(
                AudioToTextRoute::JOB_AI_AUDIO_GENERATE,
                ['publicId' => $row->job->publicId],
            )) ?>">
                <?= $csrf->hiddenInput() ?>
                <input type="hidden" name="output_type" value="<?= Html::encode($row->outputType->value) ?>">
                <?php
                // The digest this page was rendered from. If somebody corrects the transcript in another
                // tab, the button that was pressed was labelled with the old text and is not the one to
                // honour — the same idiom `review_count` already applies to speaker corrections.
            ?>
                <input type="hidden" name="expected_hash" value="<?= Html::encode($row->currentHash) ?>">

                <p class="util-muted">
                    <?= number_format($row->characterCount) ?> characters
                    <?php if ($row->turnCount > 1): ?>
                        across <?= $row->turnCount ?> turns
                    <?php endif; ?>
                    will be sent to the speech provider. This is a paid action.
                </p>

                <button class="btn btn--primary" type="submit"><?= Html::encode($row->buttonLabel()) ?></button>
            </form>
        <?php elseif ($row->state === AiAudioState::Ready): ?>
            <p class="util-muted">This audio is up to date with the current transcript.</p>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
