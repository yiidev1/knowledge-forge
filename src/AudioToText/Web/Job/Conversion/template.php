<?php

declare(strict_types=1);

use App\AudioToText\Domain\AudioConversation;
use App\AudioToText\Domain\AudioStore;
use App\AudioToText\Domain\JobStatus;
use App\AudioToText\Domain\SourceRole;
use App\AudioToText\Domain\TranscriptionJob;
use App\AudioToText\Web\AudioToTextRoute;
use App\Shared\Application\Time\AppTimeZone;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * A separate Customer + Agent conversion. Common conversions never reach here — the action redirects
 * them to the existing job page, which already knows how to show one mixed recording.
 *
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var AudioConversation $conversation
 * @var AudioStore|null $store
 * @var TranscriptionJob|null $customer
 * @var TranscriptionJob|null $agent
 * @var int|null $retentionHours
 * @var AppTimeZone $appTimeZone
 */

$this->setTitle('Conversion');
$this->setParameter('breadcrumbs', array_values(array_filter([
    ['label' => 'Audio to Text', 'route' => AudioToTextRoute::PAGE],
    $store === null ? null : [
        'label' => $store->name,
        'route' => AudioToTextRoute::STORE,
        'arguments' => ['sourceId' => $store->sourceId],
    ],
    ['label' => 'Conversion'],
])));

$status = $conversation->status();

$localTime = static fn(?DateTimeImmutable $at): string => $at === null
    ? '—'
    : $appTimeZone->format($at, 'M j, Y g:i A');

$duration = static fn(?float $seconds): string => $seconds === null
    ? '—'
    : number_format($seconds, 1) . 's';
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title">Conversion</h1>
        <p class="page-header__subtitle">
            Two recordings of one call — one per person, each transcribed on its own.
        </p>
    </div>
    <?php if ($store !== null): ?>
        <a class="btn" href="<?= Html::encode($urlGenerator->generate(AudioToTextRoute::STORE, ['sourceId' => $store->sourceId])) ?>">
            Back to <?= Html::encode($store->name) ?>
        </a>
    <?php endif; ?>
</div>

<div class="card">
    <div class="a2t-job__header">
        <span class="a2t-badge a2t-badge--<?= Html::encode($status->badgeModifier()) ?>">
            <?= Html::encode($status->label()) ?>
        </span>
        <span class="a2t-job__stage"><?= Html::encode($conversation->mode->label()) ?></span>
    </div>

    <dl class="a2t-meta">
        <div><dt>Store</dt><dd><?= Html::encode($store->name ?? 'Not associated with a store') ?></dd></div>
        <div><dt>Uploaded by</dt><dd><?= Html::encode($conversation->uploadedByUsername ?? '—') ?></dd></div>
        <div><dt>Uploaded at</dt><dd><?= Html::encode($localTime($conversation->createdAt)) ?></dd></div>
        <div><dt>Total duration</dt><dd><?= Html::encode($duration($conversation->totalDurationSeconds())) ?></dd></div>
    </dl>

    <?php
    // Said plainly rather than left for the reader to notice from two missing buttons. The roles here
    // are a fact the administrator supplied, not a conclusion the machine reached, so there is nothing
    // to confirm and nothing to correct — and the pipeline never spent a minute of CPU rediscovering
    // what it had already been told.
?>
    <p class="util-muted">
        You told us who is on each recording, so the speakers were not analysed and there is nothing to
        correct here. The two files were recorded independently and carry no shared clock, so they are
        shown separately rather than merged into a single conversation.
    </p>
</div>

<div class="a2t-split">
    <?php foreach ([
        [SourceRole::Customer, $customer],
        [SourceRole::Agent, $agent],
    ] as [$role, $job]): ?>
        <?php /** @var SourceRole $role */ ?>
        <?php /** @var TranscriptionJob|null $job */ ?>
        <div class="card">
            <div class="a2t-section__header">
                <h2 class="card__title"><?= Html::encode($role->label()) ?></h2>
                <?php if ($job !== null && $job->status === JobStatus::COMPLETED): ?>
                    <a class="btn btn--sm" href="<?= Html::encode($urlGenerator->generate(
                        AudioToTextRoute::JOB_DOWNLOAD,
                        ['publicId' => $job->publicId],
                        ['part' => 'transcript'],
                    )) ?>">Download text file</a>
                <?php endif; ?>
            </div>

            <?php if ($job === null): ?>
                <p class="util-muted">This recording is no longer on the server.</p>
            <?php else: ?>
                <div class="a2t-job__header">
                    <span class="a2t-badge a2t-badge--<?= Html::encode(strtolower($job->status->value)) ?>">
                        <?= Html::encode($job->status->label()) ?>
                    </span>
                    <span class="a2t-job__stage">Stage: <?= Html::encode($job->stage?->label() ?? '—') ?></span>
                </div>

                <dl class="a2t-meta">
                    <div><dt>File</dt><dd><?= Html::encode($job->originalFilename) ?></dd></div>
                    <div><dt>Duration</dt><dd><?= Html::encode($duration($job->durationSeconds)) ?></dd></div>
                    <div><dt>Language</dt><dd><?= Html::encode($job->detectedLanguage ?? '—') ?></dd></div>
                    <div><dt>Completed</dt><dd><?= Html::encode($localTime($job->completedAt)) ?></dd></div>
                </dl>

                <?php if ($job->status === JobStatus::COMPLETED): ?>
                    <pre class="a2t-transcript"><?= Html::encode($job->transcript ?? '') ?></pre>
                <?php elseif ($job->status === JobStatus::FAILED): ?>
                    <?php
                    // Shown against this recording alone. A failed Agent file must not make a perfectly
                    // good Customer transcript beside it look lost, which is the whole reason a
                    // conversation can be partially completed.
                    ?>
                    <div class="alert alert--error" role="alert">
                        <?= Html::encode($job->errorMessage ?? 'That recording could not be transcribed.') ?>
                    </div>
                <?php elseif ($job->status === JobStatus::QUEUED): ?>
                    <p class="util-muted">Waiting for the transcription worker to pick it up.</p>
                <?php else: ?>
                    <p class="util-muted">Transcribing. Reload this page to see the result.</p>
                <?php endif; ?>

                <p class="util-muted">
                    <a href="<?= Html::encode($urlGenerator->generate(AudioToTextRoute::JOB, ['publicId' => $job->publicId])) ?>">
                        Full conversion details
                    </a>
                </p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<p class="util-muted">
    <?php if ($retentionHours === null): ?>
        Conversions and their recordings are kept on this server indefinitely.
    <?php else: ?>
        Conversions and their recordings are kept for <?= $retentionHours ?> hours, then removed.
    <?php endif; ?>
</p>
