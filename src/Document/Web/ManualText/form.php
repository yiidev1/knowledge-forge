<?php

declare(strict_types=1);

use App\KnowledgeBase\Domain\KnowledgeBase;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\Csrf;

/**
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var Csrf $csrf
 * @var KnowledgeBase $knowledgeBase
 * @var string $mode        'create' or 'edit'
 * @var int|null $documentId set when editing
 * @var string $title
 * @var string $content
 */

// The first statement references $this so Psalm keeps the file-level @var docblock in scope.
$this->setTitle($mode === 'edit' ? 'Edit manual text' : 'Add manual text');

$isEdit = $mode === 'edit';

$slug = $knowledgeBase->slug();
$showUrl = $urlGenerator->generate('kb.show', ['slug' => $slug]);
$this->setParameter('breadcrumbs', [
    ['label' => 'Knowledge bases', 'route' => 'kb.index'],
    ['label' => $knowledgeBase->name(), 'route' => 'kb.show', 'arguments' => ['slug' => $slug]],
    ['label' => $isEdit ? 'Edit manual text' : 'Add manual text'],
]);

$action = $isEdit && $documentId !== null
    ? $urlGenerator->generate('kb.documents.edit', ['slug' => $slug, 'documentId' => $documentId])
    : $urlGenerator->generate('kb.manual-text', ['slug' => $slug]);
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= $isEdit ? 'Edit manual text' : 'Add manual text' ?></h1>
        <p class="page-header__subtitle util-mono">/<?= Html::encode($slug) ?></p>
    </div>
</div>

<div class="card">
    <p class="field__hint" style="margin-top: 0;">
        Typed knowledge is indexed exactly as written — no file needed. It is queued and indexed in the
        background, and can be edited or disabled later.
    </p>
    <form method="post" action="<?= Html::encode($action) ?>" style="max-width: 720px;">
        <?= $csrf->hiddenInput() ?>
        <div class="field">
            <label class="field__label" for="manual-title">Title</label>
            <input class="field__control" type="text" id="manual-title" name="title"
                   value="<?= Html::encode($title) ?>" maxlength="200"
                   placeholder="e.g. Refund policy" required>
        </div>
        <div class="field">
            <label class="field__label" for="manual-content">Content</label>
            <textarea class="field__control" id="manual-content" name="content" rows="16"
                      maxlength="100000" placeholder="Type the knowledge to index…" required><?= Html::encode($content) ?></textarea>
            <div class="field__hint">Plain text or Markdown. Formatting is stored as written, never rendered as HTML.</div>
        </div>
        <div class="util-row">
            <button class="btn btn--primary" type="submit"><?= $isEdit ? 'Save changes' : 'Save manual text' ?></button>
            <a class="btn btn--secondary" href="<?= Html::encode($showUrl) ?>">Cancel</a>
        </div>
    </form>
</div>
