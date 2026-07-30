<?php

declare(strict_types=1);

use App\KnowledgeBase\Domain\KnowledgeBase;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var KnowledgeBase $knowledgeBase
 * @var array{name?: string, description?: string, system_instructions?: string} $values
 * @var array<string, string> $errors
 */

$this->setTitle('Edit ' . $knowledgeBase->name());
$this->setParameter('breadcrumbs', [
    ['label' => 'Knowledge bases', 'route' => 'kb.index'],
    ['label' => $knowledgeBase->name(), 'route' => 'kb.show', 'arguments' => ['slug' => $knowledgeBase->slug()]],
    ['label' => 'Edit'],
]);

$showUrl = $urlGenerator->generate('kb.show', ['slug' => $knowledgeBase->slug()]);
?>
<div class="page-header">
    <h1 class="page-header__title">Edit knowledge base</h1>
</div>

<div class="card" style="max-width: 720px;">
    <?= $this->render(dirname(__DIR__) . '/_form', [
        'actionUrl' => $urlGenerator->generate('kb.update', ['slug' => $knowledgeBase->slug()]),
        'submitLabel' => 'Save changes',
        'cancelUrl' => $showUrl,
        'values' => $values,
        'errors' => $errors,
        'slug' => $knowledgeBase->slug(),
    ]) ?>
</div>
