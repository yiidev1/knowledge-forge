<?php

declare(strict_types=1);

use Yiisoft\Router\UrlGeneratorInterface;

/**
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var array{name?: string, description?: string, system_instructions?: string} $values
 * @var array<string, string> $errors
 */

$this->setTitle('New knowledge base');
$this->setParameter('breadcrumbs', [
    ['label' => 'Knowledge bases', 'route' => 'kb.index'],
    ['label' => 'New'],
]);

$indexUrl = $urlGenerator->generate('kb.index');
?>
<div class="page-header">
    <h1 class="page-header__title">New knowledge base</h1>
</div>

<div class="card" style="max-width: 720px;">
    <?= $this->render(dirname(__DIR__) . '/_form', [
        'actionUrl' => $urlGenerator->generate('kb.store'),
        'submitLabel' => 'Create knowledge base',
        'cancelUrl' => $indexUrl,
        'values' => $values,
        'errors' => $errors,
        'slug' => null,
    ]) ?>
</div>

<p class="field__hint" style="max-width: 720px;">
    After creating, the knowledge base is queued for provisioning. Uploads and chat become available
    once its vector store is ready.
</p>
