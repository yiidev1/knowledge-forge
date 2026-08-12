<?php

declare(strict_types=1);

use App\Chat\Domain\ChatSourceItem;
use Yiisoft\Html\Html;

/**
 * "Knowledge available to this chat" — shared by the admin and agent surfaces, which differ only in the
 * context name and the back link. Strictly read-only: no upload, edit, delete, retry or sync control appears
 * here in either realm, because the page exists to explain what the AI may use, not to change it.
 *
 * @var Yiisoft\View\WebView $this
 * @var string $title
 * @var string $contextName
 * @var list<ChatSourceItem> $items
 * @var int $retrievableCount
 * @var string $backUrl
 * @var string $backLabel
 */

$this->setTitle($title);
$total = count($items);
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title">Knowledge available to this chat</h1>
        <p class="page-header__subtitle">
            The documents this chat is allowed to use when answering about
            <strong><?= Html::encode($contextName) ?></strong>. An answer can only cite something listed here as
            <strong>available</strong>; nothing else is searched. Select a document title to read its content.
        </p>
    </div>
    <a class="btn btn--secondary btn--sm" href="<?= Html::encode($backUrl) ?>">← <?= Html::encode($backLabel) ?></a>
</div>

<section class="card">
    <div class="util-row" style="justify-content: space-between; align-items: baseline;">
        <h2 class="card__title" style="margin: 0;">
            <?= $total ?> document<?= $total === 1 ? '' : 's' ?>
        </h2>
        <?php if ($total > 0): ?>
            <span class="util-muted"><?= $retrievableCount ?> available to this chat</span>
        <?php endif; ?>
    </div>

    <?php if ($items === []): ?>
        <p class="util-muted">
            No knowledge is available to this chat yet, so it cannot answer from stored documents.
        </p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--sources">
                <thead>
                    <tr>
                        <th>Document</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td>
                                <?php if ($item->hasPreview()): ?>
                                    <details class="src-detail">
                                        <summary><?= Html::encode($item->title) ?></summary>
                                        <div class="src-detail__body"><?= Html::encode((string) $item->preview) ?></div>
                                        <?php if ($item->previewTruncated): ?>
                                            <p class="src-detail__note">Shown in part — the indexed document continues beyond this point.</p>
                                        <?php endif; ?>
                                    </details>
                                <?php else: ?>
                                    <?= Html::encode($item->title) ?>
                                    <div class="src-detail__note">No readable text for this document.</div>
                                <?php endif; ?>
                                <?php if (!$item->retrievable): ?>
                                    <div class="src-detail__note">
                                        Not available to this chat — <?= Html::encode((string) $item->unavailableReason()) ?>.
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
