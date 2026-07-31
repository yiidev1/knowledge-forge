<?php

declare(strict_types=1);

use App\Order58\Domain\AgentMirror;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var list<AgentMirror> $agents
 */

$this->setTitle('Order58 agents');
$this->setParameter('breadcrumbs', [
    ['label' => 'Order58 Data Management', 'url' => $urlGenerator->generate('order58.index')],
    ['label' => 'Agents'],
]);
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title">Order58 agents</h1>
        <p class="page-header__subtitle">Mirrored, read-only agent profiles. No passwords or tokens are ever stored. <code>account_id</code> is employer data only — never a store permission.</p>
    </div>
    <a class="btn btn--secondary" href="<?= Html::encode($urlGenerator->generate('order58.index')) ?>">Back</a>
</div>

<?php if ($agents === []): ?>
    <div class="empty">
        <div class="empty__title">No agents synced yet</div>
        <p>Run <strong>Sync Agents</strong> from Order58 Data Management.</p>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>Admin ID</th><th>Username</th><th>Name</th><th>User type</th><th>Status</th><th>Employer (account_id)</th><th>Last synced</th></tr>
            </thead>
            <tbody>
                <?php foreach ($agents as $agent): ?>
                    <tr>
                        <td><?= $agent->adminId ?></td>
                        <td><?= Html::encode($agent->username) ?></td>
                        <td><?= Html::encode($agent->displayName()) ?></td>
                        <td><?= Html::encode($agent->userType) ?></td>
                        <td>
                            <span class="badge badge--<?= $agent->isActive() ? 'ready' : 'muted' ?>"><?= Html::encode($agent->status) ?></span>
                        </td>
                        <td class="util-muted"><?= $agent->accountId ?? '—' ?></td>
                        <td class="util-muted"><?= Html::encode($agent->syncedAt?->format('Y-m-d H:i') ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
