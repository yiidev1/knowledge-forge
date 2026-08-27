<?php

declare(strict_types=1);

use App\Auth\Domain\AdminUser;
use App\Shared\Web\Support\AlphabetIndex;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\Csrf;

/**
 * @var string $activeRoute
 * @var string $activeLetter
 * @var AdminUser|null $admin
 * @var UrlGeneratorInterface $urlGenerator
 * @var \App\Shared\ApplicationParams $applicationParams
 * @var Csrf $csrf
 * @var Yiisoft\View\WebView $this
 */

$appName = $applicationParams->name;

// All typed logic is done here, in one PHP block, so the markup below only echoes prepared strings.
// Navigation items whose route is not registered yet (their phase has not landed) are skipped, so the
// sidebar never links to a 404 and grows as the application does.
$items = [
    ['label' => 'Dashboard', 'icon' => '◧', 'route' => 'dashboard', 'match' => ['dashboard']],
    // ['label' => 'Knowledge bases', 'icon' => '❏', 'route' => 'kb.index', 'match' => ['kb.']],
    // ['label' => 'Order58 sync', 'icon' => '⇄', 'route' => 'order58.index', 'match' => ['order58.index', 'order58.sync', 'order58.check', 'order58.agents']],
    ['label' => 'Order58 stores', 'icon' => '🏬', 'route' => 'order58.stores', 'match' => ['order58.stores', 'order58.store.']],
    ['label' => 'Store chat', 'icon' => '💬', 'route' => 'order58.store-chat', 'match' => ['order58.store-chat']],
    ['label' => 'Rule Chat', 'icon' => '📜', 'route' => 'admin.rule-chat.index', 'match' => ['admin.rule-chat.']],
    ['label' => 'Rule list', 'icon' => '📋', 'route' => 'order58.rules.readiness', 'match' => ['order58.rules.readiness']],
    // Prefix is the whole `admin.reports.` namespace, so a future second report lights the same entry and
    // no sibling route lights this one.
    ['label' => 'Chat Reports', 'icon' => '📊', 'route' => 'admin.reports.chat', 'match' => ['admin.reports.']],
    // Prefix matches the job, list, status and download routes too, so the entry stays lit throughout.
    ['label' => 'Audio to Text', 'icon' => '🎙', 'route' => 'audio-to-text', 'match' => ['audio-to-text']],
];

$navItems = [];
foreach ($items as $item) {
    try {
        $href = $urlGenerator->generate($item['route']);
    } catch (Throwable) {
        continue;
    }

    $active = false;
    foreach ($item['match'] as $prefix) {
        if ($activeRoute === $prefix || str_starts_with($activeRoute, $prefix)) {
            $active = true;
            break;
        }
    }

    $navItems[] = ['label' => $item['label'], 'icon' => $item['icon'], 'href' => $href, 'active' => $active];
}

$adminName = $admin?->username();
$logoutUrl = $admin !== null ? $urlGenerator->generate('auth.logout') : null;
$csrfInput = $admin !== null ? (string) $csrf->hiddenInput() : '';

// Knowledge Bases A–Z: reuses a store listing (`?letter=`) — no duplicate listing logic. The letters follow the
// page you are on: on Store chat they filter Store chat, otherwise the Order58 store directory. The group is
// expanded and its active letter highlighted on either page. The listing owns the SQL pagination/filter/count work.
// $onStoreChat = str_starts_with($activeRoute, 'order58.store-chat');
// $onStores = str_starts_with($activeRoute, 'order58.stores');
// $kbTargetRoute = $onStoreChat ? 'order58.store-chat' : 'order58.stores';

$onStoreChat = str_starts_with($activeRoute, 'order58.store-chat');
$kbTargetRoute = 'order58.store-chat';

$kbBase = null;
try {
    $kbBase = $urlGenerator->generate($kbTargetRoute);
} catch (Throwable) {
    $kbBase = null;
}
// $kbActive = $onStores || $onStoreChat;
$kbActive = $onStoreChat;
?>
<aside class="sidebar">
    <div class="sidebar__brand">
        <span class="sidebar__brand-mark">KF</span>
        <span><?= Html::encode($appName) ?></span>
    </div>

    <div class="sidebar__section">Manage</div>
    <nav class="sidebar__nav">
        <?php foreach ($navItems as $navItem): ?>
            <a
                class="sidebar__link<?= $navItem['active'] ? ' sidebar__link--active' : '' ?>"
                href="<?= Html::encode($navItem['href']) ?>">
                <span class="sidebar__link-icon" aria-hidden="true"><?= Html::encode($navItem['icon']) ?></span>
                <span><?= Html::encode($navItem['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php if ($kbBase !== null): ?>
        <details class="sidebar__kb" <?= $kbActive ? ' open' : '' ?>>
            <summary class="sidebar__link sidebar__kb-summary<?= $kbActive ? ' sidebar__link--active' : '' ?>">
                <span class="sidebar__link-icon" aria-hidden="true">🗂</span>
                <span>Knowledge Bases</span>
                <span class="sidebar__kb-caret" aria-hidden="true">▾</span>
            </summary>
            <div class="sidebar__az" role="group" aria-label="Browse knowledge bases A to Z">
                <a class="sidebar__az-item<?= $kbActive && $activeLetter === AlphabetIndex::ALL ? ' sidebar__az-item--active' : '' ?>"
                    href="<?= Html::encode($kbBase) ?>">All</a>
                <?php foreach (AlphabetIndex::letters() as $letter): ?>
                    <a class="sidebar__az-item<?= $kbActive && $activeLetter === $letter ? ' sidebar__az-item--active' : '' ?>"
                        href="<?= Html::encode($kbBase . '?' . http_build_query(['letter' => $letter])) ?>"><?= Html::encode($letter) ?></a>
                <?php endforeach; ?>
            </div>
        </details>
    <?php endif; ?>

    <div class="sidebar__spacer"></div>

    <?php if ($adminName !== null && $logoutUrl !== null): ?>
        <div class="sidebar__user">
            <div class="sidebar__user-name"><?= Html::encode($adminName) ?></div>
            <div class="sidebar__user-role">Administrator</div>
            <form class="sidebar__logout" method="post" action="<?= Html::encode($logoutUrl) ?>">
                <?= $csrfInput ?>
                <button type="submit" class="btn btn--secondary btn--sm btn--block">Sign out</button>
            </form>
        </div>
    <?php endif; ?>
</aside>