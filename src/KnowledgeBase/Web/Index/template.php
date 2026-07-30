<?php

declare(strict_types=1);

use App\KnowledgeBase\Application\Dto\KnowledgeBaseSummary;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var list<KnowledgeBaseSummary> $summaries
 * @var bool $includeArchived
 */

$this->setTitle('Knowledge bases');
$this->setParameter('breadcrumbs', [['label' => 'Knowledge bases']]);

$createUrl = $urlGenerator->generate('kb.create');
$listUrl = $urlGenerator->generate('kb.index');
$archivedUrl = $urlGenerator->generate('kb.index', ['archived' => '1']);
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title">Knowledge bases</h1>
        <p class="page-header__subtitle">Collections of documents your assistant can answer from.</p>
    </div>
    <a class="btn btn--primary" href="<?= Html::encode($createUrl) ?>">New knowledge base</a>
</div>

<div class="tabbar" role="tablist">
    <a class="tab <?= $includeArchived ? '' : 'tab--active' ?>" href="<?= Html::encode($listUrl) ?>"<?= $includeArchived ? '' : ' aria-current="page"' ?>>Active</a>
    <a class="tab <?= $includeArchived ? 'tab--active' : '' ?>" href="<?= Html::encode($archivedUrl) ?>"<?= $includeArchived ? ' aria-current="page"' : '' ?>>Including archived</a>
</div>

<?php if ($summaries === []): ?>
    <div class="empty">
        <div class="empty__icon" aria-hidden="true">❏</div>
        <div class="empty__title">No knowledge bases yet</div>
        <p>Create your first knowledge base to start uploading documents.</p>
        <p><a class="btn btn--primary" href="<?= Html::encode($createUrl) ?>">New knowledge base</a></p>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Status</th>
                    <th>Rules</th>
                    <th>Created</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($summaries as $summary): ?>
                    <?php
                    $kb = $summary->knowledgeBase;
                    $showUrl = $urlGenerator->generate('kb.show', ['slug' => $kb->slug()]);
                    $vs = $kb->vectorStoreStatus();
                    ?>
                    <tr>
                        <td>
                            <a href="<?= Html::encode($showUrl) ?>"><strong><?= Html::encode($kb->name()) ?></strong></a>
                            <?php if ($kb->isArchived()): ?>
                                <span class="badge badge--muted">Archived</span>
                            <?php endif; ?>
                            <?php if ($kb->description() !== null): ?>
                                <div class="field__hint"><?= Html::encode($kb->description()) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge badge--<?= Html::encode($vs->badge()) ?>"><span class="badge__dot"></span><?= Html::encode($vs->label()) ?></span></td>
                        <td><?= $summary->enabledRuleCount ?> enabled / <?= $summary->ruleCount ?> total</td>
                        <td class="util-muted"><?= Html::encode($kb->createdAt()->format('Y-m-d')) ?></td>
                        <td><a class="btn btn--secondary btn--sm" href="<?= Html::encode($showUrl) ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
