<?php

declare(strict_types=1);

use Yiisoft\Html\Html;
use Yiisoft\Yii\View\Renderer\Csrf;

/**
 * Shared create/edit form for a knowledge base.
 *
 * @var Yiisoft\View\WebView $this
 * @var Csrf $csrf
 * @var string $actionUrl
 * @var string $submitLabel
 * @var string $cancelUrl
 * @var array{name?: string, description?: string, system_instructions?: string} $values
 * @var array<string, string> $errors
 * @var string|null $slug Existing slug shown read-only when editing; null when creating.
 */

$name = $values['name'] ?? '';
$description = $values['description'] ?? '';
$instructions = $values['system_instructions'] ?? '';
$errorClass = static fn(string $field): string => isset($errors[$field]) ? ' field__control--error' : '';
?>
<form method="post" action="<?= Html::encode($actionUrl) ?>" novalidate>
    <?= $csrf->hiddenInput() ?>

    <div class="field">
        <label class="field__label" for="name">Name</label>
        <input class="field__control<?= $errorClass('name') ?>" type="text" id="name" name="name"
               value="<?= Html::encode($name) ?>" maxlength="160" autofocus required>
        <?php if (isset($errors['name'])): ?>
            <div class="field__error"><?= Html::encode($errors['name']) ?></div>
        <?php endif; ?>
    </div>

    <?php if ($slug !== null): ?>
        <div class="field">
            <label class="field__label" for="slug">Slug</label>
            <input class="field__control" type="text" id="slug" value="<?= Html::encode($slug) ?>" disabled>
            <div class="field__hint">The slug is fixed once created, so existing links keep working.</div>
        </div>
    <?php endif; ?>

    <div class="field">
        <label class="field__label" for="description">Description <span class="util-muted">(optional)</span></label>
        <textarea class="field__control<?= $errorClass('description') ?>" id="description" name="description"
                  maxlength="2000" rows="2"><?= Html::encode($description) ?></textarea>
        <?php if (isset($errors['description'])): ?>
            <div class="field__error"><?= Html::encode($errors['description']) ?></div>
        <?php endif; ?>
    </div>

    <div class="field">
        <label class="field__label" for="system_instructions">Custom AI instructions <span class="util-muted">(optional)</span></label>
        <textarea class="field__control<?= $errorClass('system_instructions') ?>" id="system_instructions"
                  name="system_instructions" maxlength="10000" rows="5"><?= Html::encode($instructions) ?></textarea>
        <div class="field__hint">Applied beneath the fixed security rules; they can never override them.</div>
        <?php if (isset($errors['system_instructions'])): ?>
            <div class="field__error"><?= Html::encode($errors['system_instructions']) ?></div>
        <?php endif; ?>
    </div>

    <div class="util-row">
        <button type="submit" class="btn btn--primary"><?= Html::encode($submitLabel) ?></button>
        <a class="btn btn--secondary" href="<?= Html::encode($cancelUrl) ?>">Cancel</a>
    </div>
</form>
