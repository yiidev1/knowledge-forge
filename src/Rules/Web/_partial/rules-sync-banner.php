<?php

declare(strict_types=1);

use App\Order58\Domain\SyncFreshness;
use App\Shared\Application\Time\AppTimeZone;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\Csrf;

/**
 * Shared Rules sync-freshness banner + enqueue-only "Sync Rules" button, used by both the Rule list and Rule
 * readiness pages. The freshness/state business logic lives in {@see \App\Order58\Application\Order58SyncFreshnessService}
 * and is rendered here from the already-computed {@see SyncFreshness}; the button posts to the existing
 * `order58.sync` (EnqueueSyncService) — no Order58 API call in the web request. Times display in the configured
 * application timezone (DB stays UTC).
 *
 * @var UrlGeneratorInterface $urlGenerator
 * @var Csrf $csrf
 * @var SyncFreshness $freshness
 * @var AppTimeZone $appTimeZone
 * @var string $returnRoute  The route to return to after enqueue (this page).
 */

$fmtTime = static fn(?DateTimeImmutable $d): string => $d === null ? 'never' : $appTimeZone->format($d);
?>
<div class="alert alert--<?= Html::encode($freshness->state->badge()) ?> rules-sync-banner">
    <div class="rules-sync-banner__info">
        <strong>Rules sync:</strong>
        <span class="badge badge--<?= Html::encode($freshness->state->badge()) ?>"><?= Html::encode($freshness->state->label()) ?></span>
        · Last success: <strong><?= Html::encode($fmtTime($freshness->lastSuccessAt)) ?></strong>
        · Last attempt: <?= Html::encode($fmtTime($freshness->lastAttemptAt)) ?><?php if ($freshness->lastAttemptStatus !== null): ?> (<?= Html::encode($freshness->lastAttemptStatus->label()) ?>)<?php endif; ?>
        <?php if ($freshness->nextScheduledAt !== null): ?> · Next: <?= Html::encode($fmtTime($freshness->nextScheduledAt)) ?><?php endif; ?>
    </div>
    <form method="post" action="<?= Html::encode($urlGenerator->generate('order58.sync')) ?>" class="inline-form">
        <?= $csrf->hiddenInput() ?>
        <input type="hidden" name="operation" value="rules">
        <input type="hidden" name="return" value="<?= Html::encode($returnRoute) ?>">
        <button class="btn btn--primary btn--sm" type="submit"<?= $freshness->syncing ? ' disabled' : '' ?>><?= $freshness->syncing ? 'Syncing…' : 'Sync Rules' ?></button>
    </form>
</div>
