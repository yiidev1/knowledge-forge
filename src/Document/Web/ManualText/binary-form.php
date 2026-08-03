<?php

declare(strict_types=1);

use App\Document\Domain\CanonicalDocument;
use App\Document\Domain\DocumentKind;
use App\KnowledgeBase\Domain\KnowledgeBase;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\Csrf;

/**
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var Csrf $csrf
 * @var KnowledgeBase $knowledgeBase
 * @var CanonicalDocument $document
 */

$isPdf = $document->kind === DocumentKind::Pdf;
$this->setTitle($isPdf ? 'Edit PDF' : 'Edit image');

$slug = $knowledgeBase->slug();
$showUrl = $urlGenerator->generate('kb.show', ['slug' => $slug]);
$action = $urlGenerator->generate('kb.documents.edit', ['slug' => $slug, 'documentId' => $document->id]);

$this->setParameter('breadcrumbs', [
    ['label' => 'Knowledge bases', 'route' => 'kb.index'],
    ['label' => $knowledgeBase->name(), 'route' => 'kb.show', 'arguments' => ['slug' => $slug]],
    ['label' => $isPdf ? 'Edit PDF' : 'Edit image'],
]);
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= $isPdf ? 'Edit PDF' : 'Edit image' ?></h1>
        <p class="page-header__subtitle util-mono"><?= Html::encode($document->originalFilename) ?></p>
    </div>
</div>

<div class="card">
    <p class="field__hint" style="margin-top: 0;">
        You can change the display title and optionally replace the original file.
        Replacing the file invalidates derived indexed text and requeues processing. OpenAI is not called in this request.
    </p>
    <form method="post" action="<?= Html::encode($action) ?>" enctype="multipart/form-data" style="max-width: 720px;">
        <?= $csrf->hiddenInput() ?>
        <div class="field">
            <label class="field__label" for="doc-title">Title</label>
            <input class="field__control" type="text" id="doc-title" name="title"
                   value="<?= Html::encode($document->displayTitle()) ?>" maxlength="200" required>
        </div>
        <div class="field">
            <label class="field__label" for="doc-file">Replace file (optional)</label>
            <input class="field__control" type="file" id="doc-file" name="replacement"
                   accept="<?= $isPdf ? 'application/pdf,.pdf' : 'image/png,image/jpeg,image/webp,.png,.jpg,.jpeg,.webp' ?>">
            <div class="field__hint">
                <?= $isPdf
                    ? 'PDF only. Must match this document type.'
                    : 'PNG, JPG, or WEBP only. Must match this document type.' ?>
            </div>
        </div>
        <div class="util-row">
            <button class="btn btn--primary" type="submit">Save changes</button>
            <a class="btn btn--secondary" href="<?= Html::encode($showUrl) ?>">Cancel</a>
        </div>
    </form>
</div>
