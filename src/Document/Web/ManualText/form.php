<?php

declare(strict_types=1);

use App\Document\Domain\DocumentSourceType;
use App\KnowledgeBase\Domain\KnowledgeBase;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\Csrf;

/**
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var Csrf $csrf
 * @var KnowledgeBase $knowledgeBase
 * @var string $mode
 * @var int|null $documentId
 * @var string $title
 * @var string $content
 * @var DocumentSourceType|null $sourceType
 * @var bool $isSourceOverridden
 * @var bool $readOnly
 */

$sourceType ??= DocumentSourceType::ManualText;
$isSourceOverridden ??= false;
$readOnly ??= false;
$isEdit = $mode === 'edit';
$isOrder58 = $sourceType->isOrder58Generated();
$isUploaded = $sourceType === DocumentSourceType::UploadedText;

$heading = match (true) {
    !$isEdit => 'Add manual text',
    $isOrder58 => 'Edit Order58 document',
    $isUploaded => 'Edit text document',
    default => 'Edit manual text',
};

$this->setTitle($heading);

$slug = $knowledgeBase->slug();
$showUrl = $urlGenerator->generate('kb.show', ['slug' => $slug]);
$this->setParameter('breadcrumbs', [
    ['label' => 'Knowledge bases', 'route' => 'kb.index'],
    ['label' => $knowledgeBase->name(), 'route' => 'kb.show', 'arguments' => ['slug' => $slug]],
    ['label' => $heading],
]);

$action = $isEdit && $documentId !== null
    ? $urlGenerator->generate('kb.documents.edit', ['slug' => $slug, 'documentId' => $documentId])
    : $urlGenerator->generate('kb.manual-text', ['slug' => $slug]);
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= Html::encode($heading) ?></h1>
        <p class="page-header__subtitle util-mono">/<?= Html::encode($slug) ?></p>
    </div>
</div>

<div class="card">
    <?php if ($isOrder58): ?>
        <p class="field__hint" style="margin-top: 0;">
            This document is synchronized from Order58. Saving changes will create a local override.
            Future Order58 synchronizations will not overwrite this document until the override is reset.
        </p>
        <?php if ($isSourceOverridden): ?>
            <p><span class="badge badge--warning">Local override active</span></p>
        <?php endif; ?>
    <?php else: ?>
        <p class="field__hint" style="margin-top: 0;">
            Typed knowledge is indexed exactly as written. It is queued and indexed in the background.
        </p>
    <?php endif; ?>

    <form method="post" action="<?= Html::encode($action) ?>" style="max-width: 720px;">
        <?= $csrf->hiddenInput() ?>
        <div class="field">
            <label class="field__label" for="manual-title">Title</label>
            <input class="field__control" type="text" id="manual-title" name="title"
                   value="<?= Html::encode($title) ?>" maxlength="200"
                   placeholder="e.g. Refund policy" required <?= $readOnly ? 'readonly' : '' ?>>
        </div>
        <div class="field">
            <label class="field__label" for="manual-content">Content</label>
            <textarea class="field__control" id="manual-content" name="content" rows="16"
                      maxlength="100000" placeholder="Type the knowledge to index…" required
                      <?= $readOnly ? 'readonly' : '' ?>><?= Html::encode($content) ?></textarea>
            <div class="field__hint">Plain text or Markdown. Formatting is stored as written, never rendered as HTML.</div>
        </div>
        <div class="util-row">
            <?php if (!$readOnly): ?>
                <button class="btn btn--primary" type="submit"><?= $isEdit ? 'Save changes' : 'Save manual text' ?></button>
            <?php endif; ?>
            <a class="btn btn--secondary" href="<?= Html::encode($showUrl) ?>">Cancel</a>
        </div>
    </form>
</div>
