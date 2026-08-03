<?php

declare(strict_types=1);

use App\Document\Domain\DocumentDisplayStatus;
use App\Document\Domain\DocumentListItem;
use App\Document\Domain\DocumentStatus;
use App\KnowledgeBase\Domain\KnowledgeBase;
use App\KnowledgeBase\Domain\Rule;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\Csrf;

/**
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var Csrf $csrf
 * @var KnowledgeBase $knowledgeBase
 * @var list<Rule> $rules
 * @var list<DocumentListItem> $documents
 * @var string $uploadAccept
 * @var int|null $order58StoreId
 */

// The first statement references $this so Psalm keeps the file-level @var docblock in scope.
$this->setTitle($knowledgeBase->name());
$this->setParameter('breadcrumbs', [
    ['label' => 'Knowledge bases', 'route' => 'kb.index'],
    ['label' => $knowledgeBase->name()],
]);

$slug = $knowledgeBase->slug();

$vs = $knowledgeBase->vectorStoreStatus();
$editUrl = $urlGenerator->generate('kb.edit', ['slug' => $slug]);
$archiveUrl = $urlGenerator->generate('kb.archive', ['slug' => $slug]);
$restoreUrl = $urlGenerator->generate('kb.restore', ['slug' => $slug]);
$addRuleUrl = $urlGenerator->generate('kb.rules.store', ['slug' => $slug]);
$reorderUrl = $urlGenerator->generate('kb.rules.reorder', ['slug' => $slug]);
$uploadUrl = $urlGenerator->generate('kb.documents.upload', ['slug' => $slug]);
$manualTextUrl = $urlGenerator->generate('kb.manual-text.create', ['slug' => $slug]);
$canManage = !$knowledgeBase->isArchived();

// The id order as displayed, so each row can build a reorder payload that swaps it with a neighbour.
$ruleIds = array_map(static fn(Rule $r): int => $r->id(), $rules);
$ruleCount = count($rules);
$csrfField = (string) $csrf->hiddenInput();

/**
 * Renders hidden order[] inputs for a given id sequence.
 *
 * @param list<int> $order
 */
$orderInputs = static function (array $order): string {
    $html = '';
    foreach ($order as $id) {
        $html .= '<input type="hidden" name="order[]" value="' . Html::encode((string) $id) . '">';
    }
    return $html;
};
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= Html::encode($knowledgeBase->name()) ?></h1>
        <p class="page-header__subtitle util-mono">/<?= Html::encode($slug) ?></p>
    </div>
    <div class="util-row">
        <?php if ($knowledgeBase->isReadyForChat()): ?>
            <a class="btn btn--primary" href="<?= Html::encode($urlGenerator->generate('chat.index', ['slug' => $slug])) ?>">Open chat</a>
        <?php endif; ?>
        <?php if ($order58StoreId !== null && $canManage): ?>
            <form method="post" action="<?= Html::encode($urlGenerator->generate('kb.sync-order58-knowledge', ['slug' => $slug])) ?>">
                <?= $csrfField ?>
                <button type="submit" class="btn btn--secondary">Sync Order58 knowledge</button>
            </form>
        <?php endif; ?>
        <a class="btn btn--secondary" href="<?= Html::encode($editUrl) ?>">Edit</a>
        <?php if ($knowledgeBase->isArchived()): ?>
            <form method="post" action="<?= Html::encode($restoreUrl) ?>">
                <?= $csrfField ?>
                <button type="submit" class="btn btn--secondary">Restore</button>
            </form>
        <?php else: ?>
            <form method="post" action="<?= Html::encode($archiveUrl) ?>"
                  data-confirm="Archive this knowledge base? It will be hidden and chat disabled. You can restore it later.">
                <?= $csrfField ?>
                <button type="submit" class="btn btn--secondary">Archive</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php
// Only surface a knowledge-base-level message when there is something the admin needs to know: it is still
// being prepared, or preparation failed. When it is ready, no technical status is shown at all.
$preparationFailed = $vs->value === 'failed';
$preparing = !$vs->isReady() && !$preparationFailed;
?>
<?php if ($knowledgeBase->isArchived() || $preparing || $preparationFailed): ?>
    <div class="card">
        <?php if ($knowledgeBase->isArchived()): ?>
            <div class="util-row" style="justify-content: flex-end;">
                <span class="badge badge--muted">Archived</span>
            </div>
        <?php endif; ?>

        <?php if ($knowledgeBase->description() !== null): ?>
            <p style="margin-top: 1rem;"><?= Html::encode($knowledgeBase->description()) ?></p>
        <?php endif; ?>

        <?php if ($preparationFailed): ?>
            <div class="alert alert--error" style="margin-top: 1rem;">
                <span class="alert__icon">✕</span>
                <span>This knowledge base could not be prepared. Try again shortly, or contact support if it keeps happening.</span>
            </div>
        <?php elseif ($preparing): ?>
            <div class="alert alert--info" style="margin-top: 1rem;">
                <span class="alert__icon">i</span>
                <span>Preparing the knowledge base. Documents will be processed automatically.</span>
            </div>
        <?php endif; ?>
    </div>
<?php elseif ($knowledgeBase->description() !== null): ?>
    <div class="card"><p><?= Html::encode($knowledgeBase->description()) ?></p></div>
<?php endif; ?>

<?php if ($knowledgeBase->systemInstructions() !== null): ?>
    <div class="card">
        <h2 class="card__title">Custom instructions</h2>
        <p class="util-muted" style="white-space: pre-wrap;"><?= Html::encode($knowledgeBase->systemInstructions()) ?></p>
    </div>
<?php endif; ?>

<div class="card">
    <div class="util-row" style="justify-content: space-between; align-items: baseline;">
        <h2 class="card__title" style="margin: 0;">Documents</h2>
        <?php if ($canManage): ?>
            <a class="btn btn--primary btn--sm" href="<?= Html::encode($manualTextUrl) ?>">+ Add manual text</a>
        <?php endif; ?>
    </div>
    <p class="field__hint" style="margin-top: .5rem;">
        Upload PDF, image or text files, or type knowledge directly. Everything is queued and indexed in the
        background. A document can be disabled to hide it from chat without deleting it.
    </p>

    <?php if ($documents === []): ?>
        <div class="empty" style="padding: 1.5rem;">
            <div class="empty__title">No documents yet</div>
            <p>Upload a file or add manual text to build this knowledge base.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap" style="margin-bottom: 1.5rem;">
            <table class="table">
                <thead>
                    <tr><th>Document</th><th style="width: 170px;">Source</th><th style="width: 90px;">Size</th><th style="width: 130px;">Status</th><th style="width: 320px;"></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($documents as $document): ?>
                        <?php $displayStatus = $document->displayStatus(); ?>
                        <tr>
                            <td>
                                <strong><?= Html::encode($document->title) ?></strong>
                                <?php if ($document->errorMessage !== null): ?>
                                    <div class="field__error"><?= Html::encode($document->errorMessage) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge badge--muted"><?= Html::encode($document->sourceType->label()) ?></span></td>
                            <td class="util-muted"><?= Html::encode($document->humanSize()) ?></td>
                            <td>
                                <span class="badge badge--<?= Html::encode($displayStatus->badge()) ?>">
                                    <?php if ($displayStatus === DocumentDisplayStatus::Processing): ?><span class="badge__dot"></span><?php endif; ?>
                                    <?= Html::encode($displayStatus->label()) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($canManage): ?>
                                    <?php
                                    $docId = $document->id;
                                    $retryUrl = $urlGenerator->generate('kb.documents.retry', ['slug' => $slug, 'documentId' => $docId]);
                                    $toggleUrl = $urlGenerator->generate('kb.documents.toggle', ['slug' => $slug, 'documentId' => $docId]);
                                    $editUrl = $urlGenerator->generate('kb.documents.edit.show', ['slug' => $slug, 'documentId' => $docId]);
                                    $deleteUrl = $urlGenerator->generate('kb.documents.delete', ['slug' => $slug, 'documentId' => $docId]);
                                    $processNowUrl = $urlGenerator->generate('kb.documents.process-now', ['slug' => $slug, 'documentId' => $docId]);
                                    $reindexUrl = $urlGenerator->generate('kb.documents.reindex', ['slug' => $slug, 'documentId' => $docId]);
                                    ?>
                                    <div class="table__actions">
                                        <?php if ($document->isManualText()): ?>
                                            <a class="btn btn--secondary btn--sm" href="<?= Html::encode($editUrl) ?>">Edit</a>
                                        <?php endif; ?>
                                        <form method="post" action="<?= Html::encode($toggleUrl) ?>">
                                            <?= $csrfField ?>
                                            <input type="hidden" name="enabled" value="<?= $document->isEnabled ? '0' : '1' ?>">
                                            <button class="btn btn--secondary btn--sm" type="submit"><?= $document->isEnabled ? 'Disable' : 'Enable' ?></button>
                                        </form>
                                        <?php if ($document->status->isInProgress()): ?>
                                            <form method="post" action="<?= Html::encode($processNowUrl) ?>">
                                                <?= $csrfField ?>
                                                <button class="btn btn--secondary btn--sm" type="submit" title="Move this document to the front of the processing queue.">Process next</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($document->status === DocumentStatus::Ready): ?>
                                            <form method="post" action="<?= Html::encode($reindexUrl) ?>"
                                                  data-confirm="Re-index this document? It will be uploaded and attached to OpenAI again.">
                                                <?= $csrfField ?>
                                                <button class="btn btn--secondary btn--sm" type="submit">Re-index</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($displayStatus === DocumentDisplayStatus::Failed): ?>
                                            <form method="post" action="<?= Html::encode($retryUrl) ?>">
                                                <?= $csrfField ?>
                                                <button class="btn btn--secondary btn--sm" type="submit">Retry</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="post" action="<?= Html::encode($deleteUrl) ?>" data-confirm="Remove this document?">
                                            <?= $csrfField ?>
                                            <button class="btn btn--danger btn--sm" type="submit">Remove</button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($canManage): ?>
        <div class="kb-subsection">
            <h3 class="kb-subsection__title">Upload a document</h3>
            <form method="post" action="<?= Html::encode($uploadUrl) ?>" enctype="multipart/form-data" style="max-width: 720px;">
                <?= $csrfField ?>
                <div class="field">
                    <input class="field__control" type="file" name="document" accept="<?= Html::encode($uploadAccept) ?>" required>
                    <div class="field__hint">PDF, PNG, JPG, WEBP, or a text file (.txt, .md).</div>
                </div>
                <button class="btn btn--primary" type="submit">Upload</button>
            </form>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <h2 class="card__title">Rules</h2>
    <p class="field__hint" style="margin-top: -0.5rem;">
        Applied in order when answering, beneath the fixed security rules. Lower rows are applied later.
    </p>

    <?php if ($rules === []): ?>
        <div class="empty" style="padding: 1.5rem;">
            <div class="empty__title">No rules yet</div>
            <p>Add a rule to shape how this knowledge base answers.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap" style="margin-bottom: 1.5rem;">
            <table class="table">
                <thead>
                    <tr><th style="width: 90px;">Order</th><th>Rule</th><th style="width: 110px;">Status</th><th style="width: 220px;"></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($rules as $index => $rule): ?>
                        <?php
                        $toggleUrl = $urlGenerator->generate('kb.rules.toggle', ['slug' => $slug, 'ruleId' => $rule->id()]);
                        $updateUrl = $urlGenerator->generate('kb.rules.update', ['slug' => $slug, 'ruleId' => $rule->id()]);
                        $deleteUrl = $urlGenerator->generate('kb.rules.delete', ['slug' => $slug, 'ruleId' => $rule->id()]);

                        $upOrder = $ruleIds;
                        if ($index > 0) {
                            [$upOrder[$index - 1], $upOrder[$index]] = [$upOrder[$index], $upOrder[$index - 1]];
                        }
                        $downOrder = $ruleIds;
                        if ($index < $ruleCount - 1) {
                            [$downOrder[$index + 1], $downOrder[$index]] = [$downOrder[$index], $downOrder[$index + 1]];
                        }
                        ?>
                        <tr>
                            <td>
                                <div class="util-row" style="gap: .25rem;">
                                    <?php if ($index > 0): ?>
                                        <form method="post" action="<?= Html::encode($reorderUrl) ?>"><?= $csrfField ?><?= $orderInputs($upOrder) ?><button class="btn btn--secondary btn--sm" type="submit" title="Move up">▲</button></form>
                                    <?php endif; ?>
                                    <?php if ($index < $ruleCount - 1): ?>
                                        <form method="post" action="<?= Html::encode($reorderUrl) ?>"><?= $csrfField ?><?= $orderInputs($downOrder) ?><button class="btn btn--secondary btn--sm" type="submit" title="Move down">▼</button></form>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <strong><?= Html::encode($rule->name()) ?></strong>
                                <div class="field__hint" style="white-space: pre-wrap;"><?= Html::encode($rule->instruction()) ?></div>
                                <details style="margin-top: .5rem;">
                                    <summary class="util-muted" style="cursor: pointer;">Edit</summary>
                                    <form method="post" action="<?= Html::encode($updateUrl) ?>" style="margin-top: .5rem;">
                                        <?= $csrfField ?>
                                        <div class="field">
                                            <input class="field__control" type="text" name="name" value="<?= Html::encode($rule->name()) ?>" maxlength="160" required>
                                        </div>
                                        <div class="field">
                                            <textarea class="field__control" name="instruction" rows="3" maxlength="5000" required><?= Html::encode($rule->instruction()) ?></textarea>
                                        </div>
                                        <button class="btn btn--primary btn--sm" type="submit">Save rule</button>
                                    </form>
                                </details>
                            </td>
                            <td>
                                <?php if ($rule->isEnabled()): ?>
                                    <span class="badge badge--success"><span class="badge__dot"></span>Enabled</span>
                                <?php else: ?>
                                    <span class="badge badge--muted">Disabled</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="table__actions">
                                    <form method="post" action="<?= Html::encode($toggleUrl) ?>">
                                        <?= $csrfField ?>
                                        <input type="hidden" name="enabled" value="<?= $rule->isEnabled() ? '0' : '1' ?>">
                                        <button class="btn btn--secondary btn--sm" type="submit"><?= $rule->isEnabled() ? 'Disable' : 'Enable' ?></button>
                                    </form>
                                    <form method="post" action="<?= Html::encode($deleteUrl) ?>" data-confirm="Delete this rule?">
                                        <?= $csrfField ?>
                                        <button class="btn btn--danger btn--sm" type="submit">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div class="kb-subsection">
        <h3 class="kb-subsection__title">Add a rule</h3>
        <form method="post" action="<?= Html::encode($addRuleUrl) ?>" style="max-width: 720px;">
            <?= $csrfField ?>
            <div class="field">
                <label class="field__label" for="rule-name">Name</label>
                <input class="field__control" type="text" id="rule-name" name="name" maxlength="160" placeholder="e.g. Answer only from the documents" required>
            </div>
            <div class="field">
                <label class="field__label" for="rule-instruction">Instruction</label>
                <textarea class="field__control" id="rule-instruction" name="instruction" rows="3" maxlength="5000" required></textarea>
            </div>
            <div class="field">
                <label><input type="checkbox" name="is_enabled" value="1" checked> Enabled</label>
            </div>
            <button class="btn btn--primary" type="submit">Add rule</button>
        </form>
    </div>
</div>
