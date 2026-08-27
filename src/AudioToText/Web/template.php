<?php

declare(strict_types=1);

use App\AudioToText\Domain\QueueSummary;
use App\AudioToText\Domain\WorkerStatusView;
use App\AudioToText\Web\AudioToTextRoute;
use App\AudioToText\Web\AudioToTextViews;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\Csrf;

/**
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var Csrf $csrf
 * @var list<string> $errors
 * @var QueueSummary $summary
 * @var WorkerStatusView $worker
 * @var string $maxUploadLabel
 * @var string $maxDurationLabel
 * @var string $extensionList
 * @var int|null $retentionHours
 */

$this->setTitle('Audio to Text');
$this->setParameter('breadcrumbs', [['label' => 'Audio to Text']]);

$jobsUrl = $urlGenerator->generate(AudioToTextRoute::JOBS);
$csrfField = (string) $csrf->hiddenInput();
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title">Audio to Text</h1>
        <p class="page-header__subtitle">
            Upload a recording and it is transcribed on this server. Nothing is sent to an external service.
        </p>
    </div>
    <a class="btn" href="<?= Html::encode($jobsUrl) ?>">View audio conversions</a>
</div>

<?= $this->render(AudioToTextViews::workerStatus(), ['summary' => $summary, 'worker' => $worker]) ?>

<?php if ($errors !== []): ?>
    <div class="alert alert--error" role="alert">
        <?php foreach ($errors as $error): ?>
            <p><?= Html::encode($error) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card">
    <form method="post" enctype="multipart/form-data" novalidate>
        <?= $csrfField ?>

        <div class="form-row">
            <label class="form-label" for="a2t-audio">Audio file</label>
            <input
                class="form-control"
                id="a2t-audio"
                type="file"
                name="audio"
                accept=".wav,.mp3,.m4a,.ogg,.webm,audio/*"
            >
            <p class="form-hint">
                <?= Html::encode($extensionList) ?> ·
                up to <?= Html::encode($maxUploadLabel) ?> ·
                up to <?= Html::encode($maxDurationLabel) ?> long.
                The recording is queued and transcribed in the background.
            </p>
        </div>

        <div class="form-actions">
            <button class="btn btn--primary" type="submit">Convert to Text</button>
        </div>
    </form>
</div>

<p class="util-muted">
    <?php if ($retentionHours === null): ?>
        Conversions and their recordings are kept on this server indefinitely.
    <?php else: ?>
        Conversions and their recordings are kept for <?= $retentionHours ?> hours, then removed.
    <?php endif; ?>
</p>
