<?php

declare(strict_types=1);

use App\Practice\Application\TopicInputValidator;
use App\Practice\Domain\StoreOption;
use App\Practice\Domain\Topic;
use App\Practice\Domain\TopicStatus;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * The one dialog that both adds and edits a topic.
 *
 * Same fields, same markup; only the form's `action` and its button differ. Two templates would be two
 * places to forget a field.
 *
 * `open` is printed server-side, so this works without JavaScript and a failed submission comes back
 * with the dialog still open and the typed values still in it. Cancel is a real link back to the clean
 * URL for the same reason.
 *
 * @var Yiisoft\View\WebView $this
 * @var Yiisoft\Yii\View\Renderer\Csrf $csrf
 * @var UrlGeneratorInterface $urlGenerator
 * @var bool $open
 * @var Topic|null $editing
 * @var string $closeUrl
 * @var callable(string, Topic|null): string $fieldValue
 * @var callable(string): string $errorClass
 * @var array<string, string> $errors
 * @var list<StoreOption> $storeOptions every selectable store, for the Store field
 */

$action = $editing === null
    ? $urlGenerator->generate('practice.topics.store')
    : $urlGenerator->generate('practice.topics.update', ['id' => $editing->id]);

$title = $editing === null ? 'Add Topic' : 'Edit Topic';
$submit = $editing === null ? 'Create topic' : 'Save changes';
$currentStatus = $fieldValue('status', $editing);
$currentStore = $fieldValue('store_source_id', $editing);

// Which topic this rendered dialog is actually for. The script compares it with what a trigger asks
// for and lets the browser navigate when they differ — which is what stops an Edit click opening the
// Add form, and stops one topic's values being shown under another topic's name.
$mode = $editing === null ? 'new' : (string) $editing->id;
?>
<dialog class="source-modal practice-dialog" id="practice-topic-dialog" data-practice-dialog
        data-practice-dialog-mode="<?= Html::encode($mode) ?>"
        aria-labelledby="practice-topic-title"<?= $open ? ' open' : '' ?>>
    <div class="source-modal__head">
        <h2 class="source-modal__title" id="practice-topic-title"><?= Html::encode($title) ?></h2>
        <a class="source-modal__close" href="<?= Html::encode($closeUrl) ?>"
           title="Close" aria-label="Close" data-practice-dialog-close>&times;</a>
    </div>

    <div class="source-modal__body">
        <form method="post" action="<?= Html::encode($action) ?>" novalidate>
            <?= $csrf->hiddenInput() ?>

            <div class="field">
                <label class="field__label" for="practice-topic-name">Topic Name</label>
                <input class="field__control<?= $errorClass('name') ?>" type="text"
                       id="practice-topic-name" name="name"
                       value="<?= Html::encode($fieldValue('name', $editing)) ?>"
                       maxlength="<?= TopicInputValidator::NAME_MAX ?>" required autofocus>
                <?php if (isset($errors['name'])): ?>
                    <div class="field__error"><?= Html::encode($errors['name']) ?></div>
                <?php else: ?>
                    <div class="field__hint">
                        Names are compared without regard to case or spacing, so one topic cannot be
                        created twice under different spellings.
                    </div>
                <?php endif; ?>
            </div>

            <div class="field">
                <label class="field__label" for="practice-topic-description">Description <span class="util-muted">(optional)</span></label>
                <textarea class="field__control<?= $errorClass('description') ?>" rows="3"
                          id="practice-topic-description" name="description"
                          maxlength="<?= TopicInputValidator::DESCRIPTION_MAX ?>"><?= Html::encode($fieldValue('description', $editing)) ?></textarea>
                <?php if (isset($errors['description'])): ?>
                    <div class="field__error"><?= Html::encode($errors['description']) ?></div>
                <?php endif; ?>
            </div>

            <?php
            // Offered on both, and on edit it shows what the topic actually is. Only these two values
            // are here: deleting keeps its own button and its own confirmation, so a rename can never
            // retire a topic by accident.
?>
            <?php
            /*
             * Which store this topic belongs to, or all of them.
             *
             * A real `<select>` carrying every option, upgraded by `practice.js` into a searchable
             * listbox. The select is what submits, what the server validates and what a browser with
             * no scripting shows, so the search box is an improvement on a control that already
             * works rather than the thing that makes it work — the same arrangement as every other
             * dialog on this page.
             *
             * Select2 was asked for and is not used; `practice.js` explains why at the picker's own
             * comment. In short: it needs jQuery, it writes inline `style` attributes that
             * `style-src 'self'` refuses, and it portals its dropdown to `<body>`, which renders
             * behind a native `<dialog>` in the top layer.
             *
             * Global is first and selected by default, so a topic created without a thought about
             * stores is global — which is what every topic was before this field existed.
             */
?>
            <div class="field">
                <label class="field__label" id="practice-topic-store-label"
                       for="practice-topic-store">Store</label>
                <div class="practice-store" data-practice-store>
                    <select class="field__control practice-store__native" id="practice-topic-store"
                            name="store_source_id" data-practice-store-select>
                        <option value=""<?= $currentStore === '' ? ' selected' : '' ?>>
                            Global (All Stores)
                        </option>
                        <?php foreach ($storeOptions as $storeOption): ?>
                            <option value="<?= Html::encode((string) $storeOption->sourceId) ?>"<?=
                    $currentStore === (string) $storeOption->sourceId ? ' selected' : '' ?>>
                                <?= Html::encode($storeOption->label()) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if (isset($errors['store_source_id'])): ?>
                    <p class="field__error"><?= Html::encode($errors['store_source_id']) ?></p>
                <?php else: ?>
                    <p class="field__hint">
                        Leave this on Global and the topic can be assigned on any store. Choose a
                        store and only that store may use it.
                    </p>
                <?php endif; ?>
            </div>

            <div class="field">
                <label class="field__label" for="practice-topic-status">Status</label>
                <select class="field__control" id="practice-topic-status" name="status">
                    <option value="<?= TopicStatus::Active->value ?>"<?= $currentStatus !== TopicStatus::Inactive->value ? ' selected' : '' ?>>
                        Active — can be assigned to orders
                    </option>
                    <option value="<?= TopicStatus::Inactive->value ?>"<?= $currentStatus === TopicStatus::Inactive->value ? ' selected' : '' ?>>
                        Inactive — <?= $editing === null ? 'created, but not offered yet' : 'kept, but not offered for new orders' ?>
                    </option>
                </select>
            </div>

            <div class="a2t-confirm__actions">
                <a class="btn btn--sm" href="<?= Html::encode($closeUrl) ?>" data-practice-dialog-close>Cancel</a>
                <button class="btn btn--primary btn--sm" type="submit"><?= Html::encode($submit) ?></button>
            </div>
        </form>
    </div>
</dialog>
