<?php

declare(strict_types=1);

use App\Order58\Domain\SyncRun;
use App\Rules\Domain\RuleReportSummary;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var RuleReportSummary $summary
 * @var SyncRun|null $latest
 */

$this->setTitle('Order58 Rules');
$this->setParameter('breadcrumbs', [['label' => 'Order58 Data Management', 'url' => $urlGenerator->generate('order58.index')], ['label' => 'Rules']]);

$fmt = static fn(?DateTimeImmutable $d): string => $d === null ? '—' : $d->format('Y-m-d H:i') . ' UTC';
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title">Order58 Rules</h1>
        <p class="page-header__subtitle">The mirrored Order58 rules, the deduplicated canonical catalog, their store-matching classification, and the materialized searchable documents. Classification is kept separate from retrieval: <strong>every active rule is globally available by default</strong> (indexed into the hidden Global Rules base) unless an admin ignores or disables it, and a store-matched rule is <em>additionally</em> indexed into its store's knowledge base. Chat answers the store first, then falls back to the global rules.</p>
    </div>
    <div class="util-row">
        <a class="btn btn--primary" href="<?= Html::encode($urlGenerator->generate('order58.rules.readiness')) ?>">Browse rules →</a>
        <a class="btn btn--secondary" href="<?= Html::encode($urlGenerator->generate('order58.index')) ?>">← Data Management</a>
    </div>
</div>

<?php if (!$summary->reconciles()): ?>
    <section class="card" style="border-left: 4px solid #b45309;">
        <h2 class="card__title">⚠ Reconciliation warning</h2>
        <p class="field__hint"><?= Html::encode(sprintf(
            '%d active source rule(s) are not accounted for by a canonical link (%d active sources, %d accounted). Re-run Sync Rules; if it persists, investigate.',
            $summary->unaccountedActiveSources(),
            $summary->sourceActive,
            $summary->accountedActiveSources,
        )) ?></p>
    </section>
<?php endif; ?>

<section class="card">
    <h2 class="card__title">Catalog summary</h2>
    <section class="grid grid--stats">
        <div class="stat"><div class="stat__value"><?= $summary->sourceActive ?> / <?= $summary->sourceTotal ?></div><div class="stat__label">Active / total source rules</div></div>
        <div class="stat"><div class="stat__value"><?= $summary->canonicalActive ?> / <?= $summary->canonicalTotal ?></div><div class="stat__label">Active / total canonical rules</div></div>
        <div class="stat"><div class="stat__value"><?= $summary->exactDuplicateSources ?></div><div class="stat__label">Exact-duplicate source rows</div></div>
        <div class="stat"><div class="stat__value"><?= $summary->possibleDuplicateGroups ?></div><div class="stat__label">Possible-duplicate groups (review)</div></div>
    </section>
    <p class="field__hint" style="margin-top: .5rem;">
        A canonical rule is one unique piece of rule content (same normalized title <em>and</em> description). Rules that merely
        share a title stay separate. "Possible-duplicate groups" are canonical rules that share a description but differ in
        title — shown for review only; they are never merged automatically.
    </p>
</section>

<section class="card">
    <h2 class="card__title">Classification</h2>
    <p class="field__hint">How the active canonical rules are currently classified. Store matching and materialization act only on <em>confirmed</em> decisions; "suggested common" and "pending" await admin review, and are never searchable.</p>
    <section class="grid grid--stats">
        <?php
        $labels = [
            'auto_matched' => 'Store matched (auto)',
            'manually_matched' => 'Store matched (manual)',
            'confirmed_common' => 'Confirmed common',
            'suggested_common' => 'Suggested common (review)',
            'ambiguous' => 'Ambiguous',
            'unmatched' => 'Unmatched',
            'pending' => 'Pending',
            'ignored' => 'Ignored',
        ];
foreach ($labels as $status => $label):
    $count = $summary->classified($status);
    if ($count === 0 && !in_array($status, ['auto_matched', 'confirmed_common', 'pending'], true)) {
        continue;
    }
    ?>
            <div class="stat"><div class="stat__value"><?= $count ?></div><div class="stat__label"><?= Html::encode($label) ?></div></div>
        <?php endforeach; ?>
    </section>
</section>

<section class="card">
    <h2 class="card__title">Review queue</h2>
    <p class="field__hint">Rules are globally searchable automatically — review is only about the store mapping. Click a card to open the filtered list.</p>
    <?php $listUrl = static fn(string $f): string => $urlGenerator->generate('order58.rules.list', [], ['filter' => $f]); ?>
    <section class="grid grid--stats">
        <a class="stat stat--link" href="<?= Html::encode($listUrl('needs_store_review')) ?>"><div class="stat__value"><?= $summary->needsReview() ?></div><div class="stat__label">Needs store review</div></a>
        <a class="stat stat--link" href="<?= Html::encode($listUrl('auto_matched')) ?>"><div class="stat__value"><?= $summary->classified('auto_matched') ?></div><div class="stat__label">Store matched (auto)</div></a>
        <a class="stat stat--link" href="<?= Html::encode($listUrl('manually_matched')) ?>"><div class="stat__value"><?= $summary->classified('manually_matched') ?></div><div class="stat__label">Store matched (admin)</div></a>
        <a class="stat stat--link" href="<?= Html::encode($listUrl('confirmed_common')) ?>"><div class="stat__value"><?= $summary->classified('confirmed_common') ?></div><div class="stat__label">Common (auto/admin)</div></a>
        <a class="stat stat--link" href="<?= Html::encode($listUrl('globally_available')) ?>"><div class="stat__value"><?= $summary->globallyAvailable ?></div><div class="stat__label">Globally available</div></a>
    </section>
    <p class="field__hint" style="margin-top: .5rem;">“Globally available” counts every active rule flagged searchable in the hidden Global Rules base — the stage-2 fallback any store can use. It is separate from a rule's store scope, which stays accurate for reporting. A store-specific rule can be globally available while remaining <code>store_specific</code>.</p>
</section>

<section class="card">
    <h2 class="card__title">Searchable rule documents</h2>
    <p class="field__hint">Materialized store-specific and confirmed-common rules, by their document lifecycle status. Only these are searchable in chat.</p>
    <section class="grid grid--stats">
        <?php foreach (['ready' => 'Ready', 'queued' => 'Queued', 'processing' => 'Processing', 'indexing' => 'Indexing', 'failed' => 'Failed', 'deleted' => 'Retired'] as $status => $label): ?>
            <?php if ($summary->documents($status) > 0 || in_array($status, ['ready', 'queued', 'failed'], true)): ?>
                <div class="stat"><div class="stat__value"><?= $summary->documents($status) ?></div><div class="stat__label"><?= Html::encode($label) ?></div></div>
            <?php endif; ?>
        <?php endforeach; ?>
    </section>
</section>

<section class="card">
    <h2 class="card__title">Latest rules sync</h2>
    <?php if ($latest === null): ?>
        <p class="util-muted">Rules have not been synced yet. Use <strong>Sync Rules</strong> on the Data Management page.</p>
    <?php else: ?>
        <?php $p = $latest->progress(); ?>
        <p>
            <span class="badge badge--<?= Html::encode($latest->status()->badge()) ?>"><?= Html::encode($latest->status()->label()) ?></span>
        </p>
        <div class="field__hint">
            <?= Html::encode(sprintf(
                '%d created · %d updated · %d unchanged · %d deactivated · %d canonical created · %d exact duplicates',
                $p->created,
                $p->updated,
                $p->unchanged,
                $p->deactivated,
                $p->canonicalCreated,
                $p->exactDuplicates,
            )) ?>
        </div>
        <p class="util-muted">Started <?= Html::encode($fmt($latest->startedAt())) ?> · Completed <?= Html::encode($fmt($latest->completedAt())) ?></p>
        <?php if ($latest->errorMessage() !== null): ?>
            <div class="field__hint"><?= Html::encode($latest->errorMessage()) ?></div>
        <?php endif; ?>
    <?php endif; ?>
</section>
