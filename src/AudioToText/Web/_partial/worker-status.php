<?php

declare(strict_types=1);

use App\AudioToText\Domain\QueueSummary;
use App\AudioToText\Domain\WorkerStatusView;
use Yiisoft\Html\Html;

/**
 * Queue counts and worker status, shared by the upload page and the conversions list.
 *
 * Nothing infrastructural is rendered: no process id, no filesystem path, no command line, no load
 * average. The label already distinguishes a timer between ticks from a timer that has stopped, which
 * is the only distinction an administrator actually needs in order to know whether to wait or to go
 * and start something.
 *
 * @var QueueSummary     $summary
 * @var WorkerStatusView $worker
 */

$detail = $worker->detail();
$state = $worker->isHealthy() ? 'ok' : 'warn';
?>
<div class="a2t-status">
    <div class="a2t-status__counts">
        <div class="a2t-count">
            <span class="a2t-count__label">Queued</span>
            <span class="a2t-count__value"><?= $summary->queued ?></span>
        </div>
        <div class="a2t-count">
            <span class="a2t-count__label">Processing</span>
            <span class="a2t-count__value"><?= $summary->processing ?></span>
        </div>
        <div class="a2t-count">
            <span class="a2t-count__label">Completed (<?= QueueSummary::windowLabel() ?>)</span>
            <span class="a2t-count__value"><?= $summary->completedLast24h ?></span>
        </div>
        <div class="a2t-count">
            <span class="a2t-count__label">Failed (<?= QueueSummary::windowLabel() ?>)</span>
            <span class="a2t-count__value"><?= $summary->failedLast24h ?></span>
        </div>
    </div>

    <div class="a2t-worker a2t-worker--<?= Html::encode($state) ?>">
        <span class="a2t-worker__label"><?= Html::encode($worker->label()) ?></span>
        <?php if ($detail !== null): ?>
            <span class="a2t-worker__detail"><?= Html::encode($detail) ?></span>
        <?php endif; ?>
    </div>
</div>
