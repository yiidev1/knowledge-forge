<?php

declare(strict_types=1);

use App\Web\Shared\Pagination\PageWindow;
use Yiisoft\Html\Html;

/**
 * Shared numbered pagination for ordinary list screens (not chat history, not Order58 API sync).
 *
 * @var Yiisoft\View\WebView $this
 * @var int $page Current 1-based page (already clamped by the action when possible)
 * @var int $pageCount Total pages
 * @var callable(int): string $pageUrl Builds a URL for a given page while preserving filters/search/sort
 */

$currentPage = PageWindow::clamp($page, $pageCount);
$window = PageWindow::items($currentPage, $pageCount);

if ($window === []) {
    return;
}

$prevDisabled = $currentPage <= 1;
$nextDisabled = $currentPage >= $pageCount;
?>
<nav class="pager" aria-label="Pagination">
    <?php if ($prevDisabled): ?>
        <span class="pager__nav btn btn--secondary btn--sm" aria-disabled="true">← Previous</span>
    <?php else: ?>
        <a class="pager__nav btn btn--secondary btn--sm" href="<?= Html::encode($pageUrl($currentPage - 1)) ?>" rel="prev">← Previous</a>
    <?php endif; ?>

    <ul class="pager__list">
        <?php foreach ($window as $item): ?>
            <?php if ($item === null): ?>
                <li class="pager__ellipsis" aria-hidden="true">…</li>
            <?php elseif ($item === $currentPage): ?>
                <li>
                    <span class="pager__item pager__item--current" aria-current="page"><?= $item ?></span>
                </li>
            <?php else: ?>
                <li>
                    <a class="pager__item" href="<?= Html::encode($pageUrl($item)) ?>"><?= $item ?></a>
                </li>
            <?php endif; ?>
        <?php endforeach; ?>
    </ul>

    <?php if ($nextDisabled): ?>
        <span class="pager__nav btn btn--secondary btn--sm" aria-disabled="true">Next →</span>
    <?php else: ?>
        <a class="pager__nav btn btn--secondary btn--sm" href="<?= Html::encode($pageUrl($currentPage + 1)) ?>" rel="next">Next →</a>
    <?php endif; ?>
</nav>
