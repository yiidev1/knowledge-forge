<?php

declare(strict_types=1);

use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var string $username
 * @var int $knowledgeBaseCount
 * @var int $ruleCount
 * @var int $ruleReadyCount
 */

$this->setTitle('Dashboard');
$this->setParameter('breadcrumbs', [['label' => 'Dashboard']]);

$kbUrl = $urlGenerator->generate('kb.index');
$storesUrl = $urlGenerator->generate('order58.stores');
$createUrl = $urlGenerator->generate('kb.create');
$rulesUrl = $urlGenerator->generate('order58.rules.readiness');
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title">Welcome back, <?= Html::encode($username) ?></h1>
        <p class="page-header__subtitle">Manage your knowledge bases, documents and chats.</p>
    </div>
    <a class="btn btn--primary" href="<?= Html::encode($createUrl) ?>">New knowledge base</a>
</div>

<div class="grid grid--stats">
    <a class="stat stat--link" href="<?= Html::encode($storesUrl) ?>">
        <div class="stat__icon" aria-hidden="true">❏</div>
        <div class="stat__label">Active knowledge bases</div>
        <div class="stat__value"><?= $knowledgeBaseCount ?></div>
        <div class="stat__hint">Browse Order58 stores →</div>
    </a>

    <a class="stat stat--link" href="<?= Html::encode($rulesUrl) ?>">
        <div class="stat__icon" aria-hidden="true">📋</div>
        <div class="stat__label">Order58 rules</div>
        <div class="stat__value"><?= $ruleCount ?></div>
        <div class="stat__hint">
            <?php if ($ruleCount === 0): ?>
                No rules synced yet →
            <?php else: ?>
                <!-- Synced is not answerable: only an indexed rule can be retrieved, so both numbers show. -->
                <?= $ruleReadyCount ?> indexed · Open rule list →
            <?php endif; ?>
        </div>
    </a>
</div>

<div class="card">
    <h2 class="card__title">Getting started</h2>
    <p class="util-muted" style="max-width: 60ch;">
        Knowledge Forge lets you build knowledge bases from your documents and chat with them, grounded
        in real sources.
    </p>
    <ol class="steps">
        <li>Create a knowledge base and give it a name and answering rules.</li>
        <li>Upload PDF and image documents; they are indexed in the background.</li>
        <li>Open a chat and ask questions answered only from your documents.</li>
    </ol>
    <div class="util-mt">
        <a class="btn btn--primary" href="<?= Html::encode($kbUrl) ?>">Go to knowledge bases</a>
    </div>
</div>
