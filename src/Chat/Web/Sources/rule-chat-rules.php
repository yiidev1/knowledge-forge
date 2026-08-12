<?php

declare(strict_types=1);

use App\Rules\Domain\RuleReadinessItem;
use App\Rules\Domain\RuleReadinessResult;
use Yiisoft\Html\Html;

/**
 * "Rules available to this chat" for RULE chat — shared by the admin and agent surfaces.
 *
 * Rows come from {@see \App\Rules\Contract\RuleReadinessReaderInterface} in `Ready` scope, which is the exact
 * derivation {@see \App\Rules\Application\CommonRulesReadiness} uses to decide whether Rule Chat may answer at
 * all: a live global projection with a completed, attached index file. A synced-but-unmaterialized rule is
 * therefore absent by construction, not by a filter written here.
 *
 * @var Yiisoft\View\WebView $this
 * @var string $title
 * @var RuleReadinessResult $result
 * @var int $page
 * @var bool $chatReady
 * @var string $pageRoute
 * @var string $backUrl
 * @var string $backLabel
 * @var Yiisoft\Router\UrlGeneratorInterface $urlGenerator
 */

$this->setTitle($title);

$pageUrl = static fn(int $target): string => $urlGenerator->generate($pageRoute, [], ['page' => $target]);
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title">Rules available to this chat</h1>
        <p class="page-header__subtitle">
            The indexed global rules Rule Chat can actually search. A rule appears here only once it has been
            materialized and indexed — a synced rule alone is not answerable. Select a rule title to read it.
        </p>
    </div>
    <a class="btn btn--secondary btn--sm" href="<?= Html::encode($backUrl) ?>">← <?= Html::encode($backLabel) ?></a>
</div>

<section class="card">
    <div class="util-row" style="justify-content: space-between; align-items: baseline;">
        <h2 class="card__title" style="margin: 0;">
            <?= $result->total ?> indexed rule<?= $result->total === 1 ? '' : 's' ?>
        </h2>
        <?php if ($result->pageCount() > 1): ?>
            <span class="util-muted">Page <?= $page ?> of <?= $result->pageCount() ?></span>
        <?php endif; ?>
    </div>

    <?php if ($result->items === []): ?>
        <p class="util-muted">
            No rules are indexed yet, so Rule Chat cannot answer from rules. Rules must be synced and
            materialized into the global rules base before they become searchable.
        </p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--sources">
                <thead>
                    <tr>
                        <th>Rule</th>
                        <th>Source id</th>
                        <th>Canonical id</th>
                        <th>Type</th>
                        <th>Scope</th>
                        <th>Status</th>
                        <th>Updated</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($result->items as $item): ?>
                        <?php /** @var RuleReadinessItem $item */ ?>
                        <tr>
                            <td>
                                <?php if ($item->hasContent()): ?>
                                    <details class="src-detail">
                                        <summary><?= Html::encode($item->title) ?></summary>
                                        <div class="src-detail__body"><?= Html::encode((string) $item->content) ?></div>
                                    </details>
                                <?php else: ?>
                                    <?= Html::encode($item->title) ?>
                                <?php endif; ?>
                            </td>
                            <td class="util-muted">#<?= $item->sourceId ?></td>
                            <td class="util-muted"><?= $item->canonicalId === null ? '—' : '#' . $item->canonicalId ?></td>
                            <td class="util-muted"><?= Html::encode($item->typeLabel()) ?></td>
                            <td class="util-muted"><?= $item->isStoreSpecific() ? Html::encode((string) $item->storeName) : 'Global' ?></td>
                            <td><span class="badge badge--<?= Html::encode($item->status->badge()) ?>"><?= Html::encode($item->status->label()) ?></span></td>
                            <td class="util-muted"><?= Html::encode($item->updatedAt) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php /* The shared numbered pager, as used by the store directory and the store-chat picker — the
                 same component, so this page paginates identically to every other list screen. */ ?>
        <?= $this->render(dirname(__DIR__, 3) . '/Web/Shared/_partial/pager', [
            'page' => $page,
            'pageCount' => $result->pageCount(),
            'pageUrl' => $pageUrl,
        ]) ?>
    <?php endif; ?>
</section>
