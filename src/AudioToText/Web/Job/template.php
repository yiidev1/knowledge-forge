<?php

declare(strict_types=1);

use App\AudioToText\Domain\JobStatus;
use App\AudioToText\Domain\EffectiveConversation;
use App\AudioToText\Domain\Speaker\ConversationView;
use App\AudioToText\Domain\SpeakerSeparationStatus;
use App\AudioToText\Domain\TranscriptionJob;
use App\AudioToText\Web\AudioToTextRoute;
use App\AudioToText\Web\AudioToTextViews;
use App\Shared\Application\Time\AppTimeZone;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var TranscriptionJob $job
 * @var ConversationView $conversation
 * @var EffectiveConversation $effective
 * @var int|null $queuePosition
 * @var int $pollSeconds
 * @var AppTimeZone $appTimeZone
 */

$this->setTitle('Audio conversion');
$this->setParameter('breadcrumbs', [
    ['label' => 'Audio to Text', 'route' => AudioToTextRoute::PAGE],
    ['label' => 'Conversion'],
]);

$statusUrl = $urlGenerator->generate(AudioToTextRoute::JOB_STATUS, ['publicId' => $job->publicId]);
$jobsUrl = $urlGenerator->generate(AudioToTextRoute::JOBS);
$uploadUrl = $urlGenerator->generate(AudioToTextRoute::PAGE);

$downloadUrl = static fn(string $part): string => $urlGenerator->generate(
    AudioToTextRoute::JOB_DOWNLOAD,
    ['publicId' => $job->publicId],
    ['part' => $part],
);

$localTime = static fn(?DateTimeImmutable $at): string => $at === null
    ? '—'
    : $appTimeZone->format($at, 'M j, Y g:i A T');

$duration = $job->durationSeconds === null ? '—' : number_format($job->durationSeconds, 1) . 's';
$separation = $job->speakerSeparationStatus;
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= Html::encode($job->originalFilename) ?></h1>
        <p class="page-header__subtitle">
            Uploaded by <?= Html::encode($job->uploadedByUsername ?? 'a removed account') ?>
            on <?= Html::encode($localTime($job->createdAt)) ?>
        </p>
    </div>
    <a class="btn" href="<?= Html::encode($jobsUrl) ?>">All conversions</a>
</div>

<?php
// The polling contract lives entirely in these data attributes. The application's CSP is
// `script-src 'self'` with no unsafe-inline, so an inline <script> would simply not run — behaviour is
// attached by delegated code in assets/main/admin.js instead. The attribute is emitted only while the
// job is still active, so a terminal job stops polling permanently.
?>
<div
    class="card a2t-job"
    <?php if ($job->isPending()): ?>
        data-a2t-poll="<?= Html::encode($statusUrl) ?>"
        data-a2t-interval="<?= max(2, $pollSeconds) * 1000 ?>"
    <?php endif; ?>
>
    <div class="a2t-job__header">
        <span class="a2t-badge a2t-badge--<?= Html::encode(strtolower($job->status->value)) ?>" data-a2t-field="status">
            <?= Html::encode($job->status->label()) ?>
        </span>
        <span class="a2t-job__stage">
            Stage: <span data-a2t-field="stage"><?= Html::encode($job->stage?->label() ?? '—') ?></span>
        </span>
    </div>

    <dl class="a2t-meta">
        <div><dt>Duration</dt><dd><?= Html::encode($duration) ?></dd></div>
        <div><dt>Language</dt><dd><?= Html::encode($job->detectedLanguage ?? '—') ?></dd></div>
        <div><dt>Speaker split</dt><dd><?= Html::encode($separation?->label() ?? '—') ?></dd></div>
        <div><dt>Completed</dt><dd><?= Html::encode($localTime($job->completedAt)) ?></dd></div>
        <?php // Whether the recording was kept — never where it is kept.?>
        <div>
            <dt>Recording</dt>
            <dd><?= $job->hasRetainedRecording() ? 'Retained on this server' : 'Not retained' ?></dd>
        </div>
    </dl>

    <?php if ($job->status === JobStatus::QUEUED): ?>
        <p>
            Queued. Your recording is waiting for the transcription worker to pick it up.
            <?php // Position by queue order, not a database id — nothing internal is exposed.?>
            <?php if ($queuePosition !== null): ?>
                <strong>Queue position: <?= $queuePosition ?></strong>
            <?php endif; ?>
        </p>
    <?php elseif ($job->status === JobStatus::PROCESSING): ?>
        <p>
            Processing. This page updates itself — a recording is transcribed at roughly real time,
            so a two-minute file takes about two minutes.
        </p>
    <?php elseif ($job->status === JobStatus::FAILED): ?>
        <div class="alert alert--error" role="alert">
            <?= Html::encode($job->errorMessage ?? 'That recording could not be transcribed.') ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($job->status === JobStatus::COMPLETED): ?>
    <div class="card">
        <div class="a2t-section__header">
            <h2 class="card__title">Complete transcript</h2>
            <a class="btn btn--sm" href="<?= Html::encode($downloadUrl('transcript')) ?>">Download text file</a>
        </div>
        <pre class="a2t-transcript"><?= Html::encode($job->transcript ?? '') ?></pre>
    </div>

    <?php
    // Gated on whether the roles may be shown as fact, not on what the machine concluded. An
    // administrator's confirmation is the other way a conversation reaches that state, and reading the
    // machine's status here would leave a confirmed call with role-labelled turns and no split cards.
    ?>
    <?php if ($conversation->rolesPublished && $effective->hasSeparatedText()): ?>
        <div class="a2t-split">
            <div class="card">
                <div class="a2t-section__header">
                    <h2 class="card__title">Customer</h2>
                    <a class="btn btn--sm" href="<?= Html::encode($downloadUrl('customer')) ?>">Download</a>
                </div>
                <pre class="a2t-transcript"><?= Html::encode($effective->customerText ?? '') ?></pre>
            </div>

            <div class="card">
                <div class="a2t-section__header">
                    <h2 class="card__title">Agent</h2>
                    <a class="btn btn--sm" href="<?= Html::encode($downloadUrl('agent')) ?>">Download</a>
                </div>
                <pre class="a2t-transcript"><?= Html::encode($effective->agentText ?? '') ?></pre>
            </div>
        </div>
    <?php elseif ($separation !== null && !$conversation->rolesPublished): ?>
        <?php
        // Deliberately explicit about *why* there is no split, rather than silently showing nothing.
        // An empty section reads as a bug; a stated reason reads as a result.
        $note = match ($separation) {
            SpeakerSeparationStatus::NEEDS_REVIEW
                => 'Separate speakers were detected, but the system could not confidently determine which '
                . 'one is the agent and which is the customer. The conversation below is therefore '
                . 'labelled by speaker rather than by role, and the complete transcript above remains '
                . 'the reliable record.',
            SpeakerSeparationStatus::NOT_SUPPORTED
                => 'Speaker separation is not enabled on this server, so only the complete transcript is available.',
            SpeakerSeparationStatus::FAILED
                => 'Speaker separation did not complete for this recording. The complete transcript above is unaffected.',
            default => null,
        };
        ?>
        <?php if ($note !== null): ?>
            <div class="alert alert--info"><?= Html::encode($note) ?></div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!$conversation->isEmpty()): ?>
        <details class="card a2t-conversation" open>
            <summary class="card__title">
                <?= $conversation->rolesPublished
                    ? 'Speaker-labelled conversation'
                    : 'Detected speakers (roles not confirmed)' ?>
            </summary>

            <?php
            // A machine result and a person's assertion are different kinds of fact, so the page says
            // which one it is showing rather than presenting both as "the answer".
        ?>
            <?php if ($job->rolesConfirmedAt !== null): ?>
                <p class="a2t-conversation__provenance">
                    Roles confirmed by an administrator on
                    <?= Html::encode($localTime($job->rolesConfirmedAt)) ?>.
                </p>
            <?php elseif ($effective->isReviewed): ?>
                <p class="a2t-conversation__provenance">
                    This conversation has been corrected by an administrator. The complete transcript
                    above is the system's original result and is unchanged.
                </p>
            <?php endif; ?>

            <p class="a2t-conversation__actions">
                <a class="btn btn--sm"
                   href="<?= Html::encode($urlGenerator->generate(
                       AudioToTextRoute::JOB_REVIEW,
                       ['publicId' => $job->publicId],
                   )) ?>">Correct speakers</a>
            </p>

            <?php if (!$conversation->rolesPublished && $conversation->hypotheses !== []): ?>
                <?php
                // Shown only as a guess, and only where it is impossible to mistake for the labels on
                // the turns below — which are neutral precisely because this guess did not qualify.
                ?>
                <div class="a2t-hypothesis">
                    <p class="a2t-hypothesis__lead">The system's best guess, which did not qualify:</p>
                    <ul class="a2t-hypothesis__list">
                        <?php foreach ($conversation->hypotheses as $roleLabel => $speakerLabel): ?>
                            <li>
                                Likely <?= Html::encode($roleLabel) ?>:
                                <?= Html::encode($speakerLabel) ?>
                            </li>
                        <?php endforeach; ?>
                        <?php if ($conversation->confidence !== null): ?>
                            <li>Role confidence: <?= Html::encode(number_format($conversation->confidence, 2)) ?></li>
                        <?php endif; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?= $this->render(AudioToTextViews::thread(), ['turns' => $conversation->turns]) ?>
        </details>
    <?php endif; ?>
<?php endif; ?>

<p class="form-actions">
    <a class="btn" href="<?= Html::encode($uploadUrl) ?>">Convert another file</a>
</p>
