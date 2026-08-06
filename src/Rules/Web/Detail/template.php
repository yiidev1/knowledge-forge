<?php

declare(strict_types=1);

use App\Rules\Domain\RuleDetail;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\Csrf;

/**
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var Csrf $csrf
 * @var RuleDetail $rule
 * @var array<int, string> $storeOptions
 */

$this->setTitle('Review rule #' . $rule->canonicalId);
$this->setParameter('breadcrumbs', [
    ['label' => 'Order58 Data Management', 'url' => $urlGenerator->generate('order58.index')],
    ['label' => 'Rules', 'url' => $urlGenerator->generate('order58.rules')],
    ['label' => 'Browse', 'url' => $urlGenerator->generate('order58.rules.list')],
    ['label' => '#' . $rule->canonicalId],
]);

$reviewUrl = $urlGenerator->generate('order58.rules.review');
$dash = static fn(?string $v): string => $v === null || $v === '' ? '—' : Html::encode($v);

$hidden = static fn(string $action): string => $csrf->hiddenInput()
    . '<input type="hidden" name="rule_id" value="' . $rule->canonicalId . '">'
    . '<input type="hidden" name="action" value="' . Html::encode($action) . '">';

$simpleForm = static fn(string $action, string $label, string $btn): string
    => '<form method="post" action="' . Html::encode($reviewUrl) . '" class="inline-form">'
    . $hidden($action)
    . '<button class="btn ' . $btn . '" type="submit">' . Html::encode($label) . '</button></form>';

$fixedStoreForm = static fn(string $action, int $storeId, string $label, string $btn): string
    => '<form method="post" action="' . Html::encode($reviewUrl) . '" class="inline-form">'
    . $hidden($action)
    . '<input type="hidden" name="store_id" value="' . $storeId . '">'
    . '<button class="btn ' . $btn . '" type="submit">' . Html::encode($label) . '</button></form>';

// The store-select form, used by Confirm/Change/Assign store. Saves the store's source_id.
// Renders a labeled field: caption above, then the constrained-width dropdown next to its submit button.
$storeSelectForm = static function (string $label, string $btn, ?int $preselect, string $caption = '') use ($reviewUrl, $hidden, $storeOptions): string {
    $options = '';
    foreach ($storeOptions as $id => $name) {
        $sel = $preselect === $id ? ' selected' : '';
        $options .= '<option value="' . $id . '"' . $sel . '>' . Html::encode($name) . ' (#' . $id . ')</option>';
    }

    $captionHtml = $caption === '' ? '' : '<label class="field__label">' . Html::encode($caption) . '</label>';

    return '<div class="field" style="margin-bottom: 0;">' . $captionHtml
        . '<form method="post" action="' . Html::encode($reviewUrl) . '" class="store-picker" role="group">'
        . $hidden('confirm_store')
        . '<select name="store_id" class="field__control store-picker__select">' . $options . '</select>'
        . '<button class="btn ' . $btn . '" type="submit">' . Html::encode($label) . '</button></form></div>';
};
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title">Rule #<?= $rule->canonicalId ?></h1>
        <p class="page-header__subtitle">
            <span class="badge badge--muted"><?= Html::encode($rule->scopeType) ?></span>
            <span class="badge badge--<?= $rule->isSearchable() ? 'ready' : 'muted' ?>"><?= Html::encode($rule->classificationStatus) ?></span>
            <?php if ($rule->isSearchable()): ?><span class="badge badge--ready">searchable</span><?php endif; ?>
        </p>
    </div>
    <div class="util-row">
        <a class="btn btn--secondary" href="<?= Html::encode($urlGenerator->generate('order58.rules.list')) ?>">← Back to list</a>
    </div>
</div>

<section class="card">
    <h2 class="card__title"><?= Html::encode($rule->title) ?></h2>
    <div class="field__hint" style="white-space: pre-wrap; margin-top: .5rem;"><?= Html::encode($rule->content) ?></div>
    <div class="table-wrap" style="margin-top: 1rem;">
        <table class="table">
            <tbody>
                <tr><th>Scope</th><td><?= Html::encode($rule->scopeType) ?></td></tr>
                <tr><th>Classification</th><td><?= Html::encode($rule->classificationStatus) ?></td></tr>
                <tr>
                    <th>Globally available</th>
                    <td>
                        <span class="badge badge--<?= $rule->isGloballyAvailable() ? 'ready' : 'muted' ?>"><?= $rule->isGloballyAvailable() ? 'yes' : 'no' ?></span>
                        <span class="field__hint">— every approved rule answers any store as a stage-2 fallback, independent of its store scope</span>
                    </td>
                </tr>
                <tr><th>Detected store text</th><td><?= $dash($rule->detectedStoreText) ?></td></tr>
                <tr><th>Confirmed store</th><td><?= $rule->matchedStoreId === null ? '—' : Html::encode(($rule->matchedStoreName ?? 'store') . ' (#' . $rule->matchedStoreId . ')') ?></td></tr>
                <tr><th>Suggested store (auto-match)</th><td><?= $rule->suggestedStoreId === null ? '—' : Html::encode(($rule->suggestedStoreName ?? 'store') . ' (#' . $rule->suggestedStoreId . ')') ?></td></tr>
            </tbody>
        </table>
    </div>
</section>

<section class="card">
    <h2 class="card__title">Review actions</h2>
    <?php if ($rule->needsReview()): ?>
        <p class="review-lede">This rule has no confirmed decision yet and is <strong>not searchable</strong>. Choose one of the actions below.</p>
        <div class="review-group">
            <?= $storeSelectForm('Confirm store', 'btn--primary', $rule->suggestedStoreId, 'Confirm a store — makes this rule searchable in that store\'s Knowledge Base (stage 1) and, like every approved rule, available to every store as a stage-2 fallback') ?>
        </div>
        <div class="review-group">
            <label class="field__label">Or classify without a specific store</label>
            <div class="util-row">
                <?= $simpleForm('mark_common', 'Mark as common', 'btn--secondary') ?>
                <?= $simpleForm('mark_unresolved', 'Mark unresolved', 'btn--secondary') ?>
                <?= $simpleForm('ignore', 'Ignore', 'btn--ghost') ?>
            </div>
            <p class="field__hint">“Mark as common” makes this rule searchable for <strong>all stores</strong> through the Global Rules fallback.</p>
        </div>

    <?php elseif ($rule->isAutoMatched() || $rule->isManuallyMatched()): ?>
        <?php $storeId = $rule->matchedStoreId ?? $rule->suggestedStoreId; ?>
        <?php $storeName = $rule->matchedStoreName ?? $rule->suggestedStoreName ?? 'the selected store'; ?>
        <?php if ($rule->isAutoMatched()): ?>
            <p class="review-lede">An automatic match <strong>suggested</strong> <?= Html::encode($storeName) ?>. It is <strong>not searchable</strong> until you confirm it — review a suspicious match before confirming.</p>
        <?php else: ?>
            <p class="review-lede">Confirmed to <strong><?= Html::encode($storeName) ?></strong> — searchable in that store’s Knowledge Base.</p>
        <?php endif; ?>
        <?php if ($storeId !== null): ?>
            <div class="review-group">
                <label class="field__label">Confirming makes this rule searchable in <?= Html::encode($storeName) ?>’s Knowledge Base (stage 1) and available to every store as a stage-2 fallback</label>
                <div class="util-row">
                    <?= $fixedStoreForm('confirm_store', $storeId, 'Confirm current store', 'btn--primary') ?>
                    <?= $fixedStoreForm('reject_store', $storeId, 'Reject store match', 'btn--secondary') ?>
                </div>
            </div>
        <?php endif; ?>
        <div class="review-group">
            <?= $storeSelectForm('Change store', 'btn--secondary', $storeId, 'Change to a different store') ?>
        </div>
        <div class="review-group">
            <label class="field__label">Other actions</label>
            <div class="util-row">
                <?= $simpleForm('mark_common', 'Mark as common', 'btn--secondary') ?>
                <?= $simpleForm('ignore', 'Ignore', 'btn--ghost') ?>
            </div>
        </div>

    <?php elseif ($rule->isConfirmedCommon()): ?>
        <p class="review-lede">Confirmed <strong>common</strong> — globally available: searchable for all stores through the Global Rules fallback.</p>
        <div class="review-group">
            <label class="field__label">Actions</label>
            <div class="util-row">
                <?= $simpleForm('mark_unresolved', 'Mark unresolved', 'btn--secondary') ?>
                <?= $simpleForm('ignore', 'Ignore', 'btn--ghost') ?>
            </div>
        </div>
        <div class="review-group">
            <?= $storeSelectForm('Assign to store', 'btn--secondary', null, 'Reassign to a specific store instead (makes it searchable in that store’s Knowledge Base)') ?>
        </div>

    <?php else: ?>
        <p class="review-lede">This rule is <strong>ignored</strong> and not searchable.</p>
        <div class="review-group">
            <?= $storeSelectForm('Assign to store', 'btn--secondary', null, 'Assign to a store to make it searchable again') ?>
        </div>
        <div class="review-group">
            <?= $simpleForm('mark_unresolved', 'Restore to review', 'btn--secondary') ?>
        </div>
    <?php endif; ?>
</section>

<section class="card">
    <h2 class="card__title">Global availability</h2>
    <p class="review-lede">Retrieval availability is separate from classification. Every active rule is globally available by default — searchable for every store at the stage-2 fallback — regardless of its store scope. Disable it to remove the rule from global search (its store projection, if any, is unaffected).</p>
    <div class="review-group">
        <p class="field__hint" style="margin-bottom: .6rem;">Currently
            <span class="badge badge--<?= $rule->isGloballyAvailable() ? 'ready' : 'muted' ?>"><?= $rule->isGloballyAvailable() ? 'globally available' : 'not globally available' ?></span>
        </p>
        <div class="util-row">
            <?php if ($rule->isGloballyAvailable()): ?>
                <?= $simpleForm('disable_global', 'Disable global search', 'btn--secondary') ?>
            <?php else: ?>
                <?= $simpleForm('enable_global', 'Enable global search', 'btn--primary') ?>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php
/** @param list<\App\Rules\Domain\RuleDocumentRow> $documents */
$docRows = static function (array $documents): string {
    if ($documents === []) {
        return '<p class="util-muted">Not projected.</p>';
    }
    $html = '<div class="table-wrap"><table class="table"><thead><tr><th>Knowledge base</th><th>Status</th></tr></thead><tbody>';
    foreach ($documents as $document) {
        /** @var \App\Rules\Domain\RuleDocumentRow $document */
        $badge = $document->status === 'ready' ? 'ready' : 'muted';
        $html .= '<tr><td>' . Html::encode($document->knowledgeBaseName) . '</td>'
            . '<td><span class="badge badge--' . $badge . '">' . Html::encode($document->status) . '</span></td></tr>';
    }

    return $html . '</tbody></table></div>';
};
?>
<section class="card">
    <h2 class="card__title">Searchable projections</h2>
    <?php if ($rule->documents === []): ?>
        <p class="util-muted">Not materialized — this rule is not searchable in chat.</p>
    <?php else: ?>
        <div class="review-group">
            <label class="field__label">Store knowledge base (stage-1, this rule's matched store only)</label>
            <?= $docRows($rule->storeDocuments()) ?>
        </div>
        <div class="review-group">
            <label class="field__label">Global Rules base (stage-2 fallback, available to every store)</label>
            <?= $docRows($rule->globalDocuments()) ?>
        </div>
        <p class="field__hint">A document stays <code>queued</code> until the background worker indexes it into OpenAI; only <code>ready</code> is searchable. Every approved rule has a global projection; only store-specific rules also have a store projection.</p>
    <?php endif; ?>
</section>

<section class="card">
    <h2 class="card__title">Source rules (<?= count($rule->sources) ?>)</h2>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Source ID</th><th>Title</th><th>Relation</th><th>Active</th></tr></thead>
            <tbody>
                <?php foreach ($rule->sources as $source): ?>
                    <tr>
                        <td>#<?= $source->sourceId ?></td>
                        <td><?= Html::encode($source->title) ?></td>
                        <td><?= Html::encode($source->relationType) ?></td>
                        <td><?= $source->isActive ? 'yes' : 'no' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card">
    <h2 class="card__title">Classification history</h2>
    <?php if ($rule->history === []): ?>
        <p class="util-muted">No classification changes recorded yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>When (UTC)</th><th>Event</th><th>Change</th><th>By admin</th><th>Note</th></tr></thead>
                <tbody>
                    <?php foreach ($rule->history as $event): ?>
                        <tr>
                            <td><?= Html::encode($event->createdAt) ?></td>
                            <td><?= Html::encode($event->eventType) ?></td>
                            <td><?= $dash($event->oldStatus) ?> → <?= $dash($event->newStatus) ?></td>
                            <td><?= $event->adminUserId === null ? 'system' : '#' . $event->adminUserId ?></td>
                            <td><?= $dash($event->message) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
