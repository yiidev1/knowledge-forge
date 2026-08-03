<?php

declare(strict_types=1);

use App\Document\Domain\CanonicalDocument;
use App\KnowledgeBase\Domain\KnowledgeBase;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var KnowledgeBase $knowledgeBase
 * @var CanonicalDocument $document
 * @var string $body
 */

$this->setTitle('View: ' . $document->displayTitle());

$slug = $knowledgeBase->slug();
$showUrl = $urlGenerator->generate('kb.show', ['slug' => $slug]);
$downloadUrl = $urlGenerator->generate('kb.documents.download', ['slug' => $slug, 'documentId' => $document->id]);
$editUrl = $urlGenerator->generate('kb.documents.edit.show', ['slug' => $slug, 'documentId' => $document->id]);

$this->setParameter('breadcrumbs', [
    ['label' => 'Knowledge bases', 'route' => 'kb.index'],
    ['label' => $knowledgeBase->name(), 'route' => 'kb.show', 'arguments' => ['slug' => $slug]],
    ['label' => 'View document'],
]);
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= Html::encode($document->displayTitle()) ?></h1>
        <p class="page-header__subtitle">
            <span class="badge badge--muted"><?= Html::encode($document->sourceType->label()) ?></span>
            <?php if ($document->isSourceOverridden): ?>
                <span class="badge badge--warning">Local override</span>
            <?php endif; ?>
        </p>
    </div>
    <div class="util-row">
        <a class="btn btn--secondary" href="<?= Html::encode($downloadUrl) ?>">Download</a>
        <a class="btn btn--secondary" href="<?= Html::encode($editUrl) ?>">Edit</a>
        <a class="btn btn--secondary" href="<?= Html::encode($showUrl) ?>">Back</a>
    </div>
</div>

<div class="card">
    <p class="field__hint" style="margin-top: 0;">Canonical local source (plain text). Not rendered as HTML.</p>
    <pre class="field__control" style="white-space: pre-wrap; max-height: 70vh; overflow: auto;"><?= Html::encode($body) ?></pre>
</div>
