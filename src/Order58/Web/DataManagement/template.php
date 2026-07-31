<?php

declare(strict_types=1);

use App\KnowledgeBase\Domain\SourceKnowledgeBaseCounts;
use App\Order58\Domain\SyncRun;
use App\Order58\Domain\SyncRunStatus;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\Csrf;

/**
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var Csrf $csrf
 * @var array{all: int, active: int} $stores
 * @var SourceKnowledgeBaseCounts $knowledgeBases
 * @var int $availableToAgents
 * @var array{all: int, active: int} $agents
 * @var array{all: int, active: int} $knowledge
 * @var array<string, SyncRun> $latest
 * @var SyncRun|null $health
 * @var list<SyncRun> $recent
 * @var array{stores: bool, knowledge: bool, agents: bool} $active
 */

$this->setTitle('Order58 Data Management');
$this->setParameter('breadcrumbs', [['label' => 'Order58 Data Management']]);

$syncUrl = $urlGenerator->generate('order58.sync');
$checkUrl = $urlGenerator->generate('order58.check');
$agentsUrl = $urlGenerator->generate('order58.agents');
$storesUrl = $urlGenerator->generate('order58.stores');

$fmt = static fn(?DateTimeImmutable $d): string => $d === null ? '—' : $d->format('Y-m-d H:i') . ' UTC';

$renderLatest = static function (?SyncRun $run) use ($fmt): string {
    if ($run === null) {
        return '<span class="util-muted">Never run</span>';
    }
    $p = $run->progress();
    $counts = Html::encode(sprintf(
        '%d created · %d updated · %d unchanged · %d deactivated · %d skipped',
        $p->created,
        $p->updated,
        $p->unchanged,
        $p->deactivated,
        $p->skippedMissingStore,
    ));
    $badge = '<span class="badge badge--' . Html::encode($run->status()->badge()) . '">'
        . Html::encode($run->status()->label()) . '</span>';
    $when = Html::encode('Started ' . $fmt($run->startedAt()) . ' · Completed ' . $fmt($run->completedAt()));
    $error = $run->errorMessage() !== null
        ? '<div class="field__hint">' . Html::encode($run->errorMessage()) . '</div>'
        : '';

    return $badge . '<div class="field__hint">' . $counts . '</div><div class="util-muted">' . $when . '</div>' . $error;
};

$button = static function (string $operation, string $label, bool $isActive) use ($syncUrl, $csrf): string {
    return '<form method="post" action="' . Html::encode($syncUrl) . '" class="inline-form">'
        . $csrf->hiddenInput()
        . '<input type="hidden" name="operation" value="' . Html::encode($operation) . '">'
        . '<button class="btn btn--primary" type="submit"' . ($isActive ? ' disabled' : '') . '>'
        . Html::encode($label) . '</button>'
        . ($isActive ? ' <span class="util-muted">Running…</span>' : '')
        . '</form>';
};
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title">Order58 Data Management</h1>
        <p class="page-header__subtitle">Synchronize stores, knowledge and agents from the Order58 Integration API. Work runs in the background worker.</p>
    </div>
    <div class="util-row">
        <a class="btn btn--secondary" href="<?= Html::encode($storesUrl) ?>">Browse stores</a>
        <a class="btn btn--secondary" href="<?= Html::encode($agentsUrl) ?>">View agents</a>
    </div>
</div>

<section class="card">
    <h2 class="card__title">API status</h2>
    <?php if ($health === null): ?>
        <p class="util-muted">Connection has not been checked yet.</p>
    <?php else: ?>
        <p>
            <span class="badge badge--<?= Html::encode($health->status()->badge()) ?>"><?= Html::encode($health->status() === SyncRunStatus::Completed ? 'Connected' : 'Unreachable') ?></span>
            <span class="util-muted"><?= Html::encode($health->errorMessage() ?? '') ?></span>
        </p>
        <p class="util-muted">Last checked <?= Html::encode($fmt($health->completedAt())) ?></p>
    <?php endif; ?>
    <form method="post" action="<?= Html::encode($checkUrl) ?>" class="inline-form">
        <?= $csrf->hiddenInput() ?>
        <button class="btn btn--secondary" type="submit">Check connection</button>
    </form>
</section>

<section class="card">
    <div class="util-row" style="justify-content: space-between; align-items: baseline;">
        <h2 class="card__title" style="margin: 0;">Store status</h2>
        <a class="btn btn--secondary btn--sm" href="<?= Html::encode($storesUrl) ?>">Browse stores →</a>
    </div>
    <p class="field__hint" style="margin-top: .35rem;">
        These are four independent axes. "Source active" mirrors Order58's <code>account.active</code>; the others are
        local: whether agents are allowed, whether the vector store is provisioned, and whether a store is fully ready for agents.
    </p>
    <section class="stat-row">
        <div class="stat"><div class="stat__value"><?= $stores['active'] ?> / <?= $stores['all'] ?></div><div class="stat__label">Source-active Order58 stores</div></div>
        <div class="stat"><div class="stat__value"><?= $knowledgeBases->agentEnabled ?> / <?= $knowledgeBases->total ?></div><div class="stat__label">Agent-enabled knowledge bases</div></div>
        <div class="stat"><div class="stat__value"><?= $knowledgeBases->ready ?> / <?= $knowledgeBases->total ?></div><div class="stat__label">Ready knowledge bases</div></div>
        <div class="stat"><div class="stat__value"><?= $availableToAgents ?></div><div class="stat__label">Stores available to agents</div></div>
    </section>
</section>

<section class="stat-row">
    <div class="stat"><div class="stat__value"><?= $knowledge['active'] ?> / <?= $knowledge['all'] ?></div><div class="stat__label">Active / total knowledge records</div></div>
    <div class="stat"><div class="stat__value"><?= $agents['active'] ?> / <?= $agents['all'] ?></div><div class="stat__label">Active / total agents</div></div>
</section>

<section class="card">
    <h2 class="card__title">Synchronize</h2>
    <p class="field__hint">Each operation is independent — a running sync only disables its own button.</p>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Operation</th><th>Latest status</th><th></th></tr></thead>
            <tbody>
                <tr>
                    <td><strong>Stores</strong><div class="field__hint">Full account scan; creates one knowledge base per store.</div></td>
                    <td><?= $renderLatest($latest['stores'] ?? null) ?></td>
                    <td><?= $button('stores', 'Sync Stores', $active['stores']) ?></td>
                </tr>
                <tr>
                    <td><strong>Knowledge</strong><div class="field__hint">Generates deterministic knowledge documents for each store.</div></td>
                    <td><?= $renderLatest($latest['knowledge'] ?? null) ?></td>
                    <td><?= $button('knowledge', 'Sync Knowledge', $active['knowledge']) ?></td>
                </tr>
                <tr>
                    <td><strong>Agents</strong><div class="field__hint">Mirrors safe agent profiles (no credentials).</div></td>
                    <td><?= $renderLatest($latest['agents'] ?? null) ?></td>
                    <td><?= $button('agents', 'Sync Agents', $active['agents']) ?></td>
                </tr>
            </tbody>
        </table>
    </div>
</section>

<section class="card">
    <h2 class="card__title">Recent synchronization history</h2>
    <?php if ($recent === []): ?>
        <p class="util-muted">No synchronization runs yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Type</th><th>Status</th><th>Started</th><th>Completed</th><th>Counts</th><th>Notes</th></tr></thead>
                <tbody>
                    <?php foreach ($recent as $run): ?>
                        <?php $p = $run->progress(); ?>
                        <tr>
                            <td><?= Html::encode($run->type()->value) ?><?= $run->scopeRef() !== null ? Html::encode(' #' . $run->scopeRef()) : '' ?></td>
                            <td><span class="badge badge--<?= Html::encode($run->status()->badge()) ?>"><?= Html::encode($run->status()->label()) ?></span></td>
                            <td class="util-muted"><?= Html::encode($fmt($run->startedAt())) ?></td>
                            <td class="util-muted"><?= Html::encode($fmt($run->completedAt())) ?></td>
                            <td class="util-muted"><?= $p->created ?>c / <?= $p->updated ?>u / <?= $p->unchanged ?>= / <?= $p->skippedMissingStore ?>skip</td>
                            <td class="field__hint"><?= Html::encode($run->errorMessage() ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
