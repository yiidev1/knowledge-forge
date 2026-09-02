<?php

declare(strict_types=1);

use App\AudioToText\Domain\QueueSummary;
use App\AudioToText\Domain\TranscriptionJobListItem;
use App\AudioToText\Domain\WorkerStatusView;
use App\AudioToText\Web\AudioToTextRoute;
use App\AudioToText\Web\AudioToTextViews;
use App\Shared\Application\Time\AppTimeZone;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var list<TranscriptionJobListItem> $items
 * @var int $total
 * @var int $limit
 * @var int $page
 * @var int $pageCount
 * @var bool $hasActive
 * @var int $pollSeconds
 * @var QueueSummary $summary
 * @var WorkerStatusView $worker
 * @var int|null $retentionHours
 * @var AppTimeZone $appTimeZone
 */

$this->setTitle('Audio conversions');
$this->setParameter('breadcrumbs', [
    ['label' => 'Audio to Text', 'route' => AudioToTextRoute::PAGE],
    ['label' => 'Conversions'],
]);

$uploadUrl = $urlGenerator->generate(AudioToTextRoute::PAGE);

// Page 1 keeps a clean URL; every other page carries ?page=N. The auto-refresh uses
// location.reload(), which preserves the query string, so a reader on page 3 stays on page 3.
$pageUrl = static fn(int $p): string => $urlGenerator->generate(AudioToTextRoute::JOBS)
    . ($p > 1 ? '?page=' . $p : '');

$firstRow = $total === 0 ? 0 : (($page - 1) * $limit) + 1;
$lastRow = min($page * $limit, $total);
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title">Audio conversions</h1>
        <p class="page-header__subtitle">
            Every conversion submitted from the admin panel, newest first.
        </p>
    </div>
    <a class="btn btn--primary" href="<?= Html::encode($uploadUrl) ?>">Convert a file</a>
</div>

<?= $this->render(AudioToTextViews::workerStatus(), ['summary' => $summary, 'worker' => $worker]) ?>

<?php if ($items === []): ?>
    <div class="card">
        <p class="util-muted">No conversions yet. Upload a recording and it will appear here.</p>
    </div>
<?php else: ?>
    <?php
    // Whole-page reload rather than the job page's status endpoint: this list has no single status to
    // watch, and a job submitted in another tab should appear here too. The attribute is emitted only
    // while something visible can still change, so a settled list stops refreshing.
    ?>
    <div
        class="card a2t-wide"
        <?php if ($hasActive): ?>
            data-a2t-reload="<?= max(2, $pollSeconds) * 1000 ?>"
        <?php endif; ?>
    >
        <div class="a2t-table-scroll">
            <table class="table a2t-table">
                <?php
                // Explicit column widths rather than letting the browser guess: with three free-text
                // preview columns, auto layout gives the widest cell in the first row a permanent say
                // over every row below it.
?>
                <colgroup>
                    <col class="a2t-col-text">
                    <col class="a2t-col-when">
                    <col class="a2t-col-status">
                    <col class="a2t-col-stage">
                    <col class="a2t-col-text">
                    <col class="a2t-col-text">
                    <col class="a2t-col-text">
                    <col class="a2t-col-split">
                    <col class="a2t-col-duration">
                    <col class="a2t-col-actions">
                </colgroup>
                <thead>
                    <tr>
                        <th>Audio</th>
                        <th>Uploaded at</th>
                        <th>Status</th>
                        <th>Stage</th>
                        <th>Full transcript</th>
                        <th>Agent text</th>
                        <th>Customer text</th>
                        <th>Speaker split</th>
                        <th>Duration</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <?php
    // View opens the correction page. A row with nothing to correct — queued, failed, or never
    // speaker-separated — is redirected on to the full detail page, so this one link is right for
    // every row and the list does not have to predict what the correction page will find.
    $viewUrl = $urlGenerator->generate(AudioToTextRoute::JOB_REVIEW, ['publicId' => $item->publicId]);
                    $downloadUrl = $urlGenerator->generate(
                        AudioToTextRoute::JOB_DOWNLOAD,
                        ['publicId' => $item->publicId],
                        ['part' => 'transcript'],
                    );
                    ?>
                    <tr>
                        <?php
                        // The uploader is still recorded and still shown on the job page; it is only
                        // absent from this table, where it competed for width with the transcripts.
                    ?>
                        <td class="a2t-cell-file" title="<?= Html::encode($item->originalFilename) ?>">
                            <?= Html::encode($item->originalFilename) ?>
                        </td>
                        <td><?= Html::encode($appTimeZone->format($item->createdAt, 'M j, Y g:i A')) ?></td>
                        <td>
                            <span class="a2t-badge a2t-badge--<?= Html::encode(strtolower($item->status->value)) ?>">
                                <?= Html::encode($item->status->label()) ?>
                            </span>
                            <?php if ($item->errorMessage !== null): ?>
                                <div class="a2t-cell-note"><?= Html::encode($item->errorMessage) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= Html::encode($item->stage?->label() ?? '—') ?></td>
                        <td class="a2t-cell-preview"><?= Html::encode($item->transcriptPreview ?? '—') ?></td>
                        <td class="a2t-cell-preview"><?= Html::encode($item->agentTextPreview ?? '—') ?></td>
                        <td class="a2t-cell-preview"><?= Html::encode($item->customerTextPreview ?? '—') ?></td>
                        <td><?= Html::encode($item->speakerSeparationStatus?->label() ?? '—') ?></td>
                        <td>
                            <?= $item->durationSeconds === null
                            ? '—'
                            : Html::encode(number_format($item->durationSeconds, 1) . 's') ?>
                        </td>
                        <td class="a2t-cell-actions">
                            <a href="<?= Html::encode($viewUrl) ?>">View</a>
                            <?php if ($item->downloadable): ?>
                                <a href="<?= Html::encode($downloadUrl) ?>">Download</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total > $limit): ?>
            <p class="util-muted">
                Showing <?= $firstRow ?>–<?= $lastRow ?> of <?= $total ?> conversions,
                newest first.
            </p>

            <?= $this->render(dirname(__DIR__, 4) . '/Web/Shared/_partial/pager', [
                'page' => $page,
                'pageCount' => $pageCount,
                'pageUrl' => $pageUrl,
            ]) ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<p class="util-muted">
    <?php if ($retentionHours === null): ?>
        Conversions and their recordings are kept indefinitely.
    <?php else: ?>
        Conversions and their recordings are kept for <?= $retentionHours ?> hours, then removed automatically.
    <?php endif; ?>
</p>
