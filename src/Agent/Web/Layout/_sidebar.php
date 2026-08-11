<?php

declare(strict_types=1);

use App\Agent\Domain\AgentIdentity;
use App\Shared\Web\Support\AlphabetIndex;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\Csrf;

/**
 * @var string $activeRoute
 * @var string $activeLetter
 * @var AgentIdentity|null $agent
 * @var UrlGeneratorInterface $urlGenerator
 * @var Csrf $csrf
 * @var Yiisoft\View\WebView $this
 */

// The agent realm is intentionally small: a single "Store List" entry (its chat lives under it). Kept as a
// list so it reads like the admin sidebar and can grow if the agent gains more pages.
$items = [
    ['label' => 'Store Knowledge', 'icon' => '🏬', 'route' => 'agent.home', 'match' => ['agent.home', 'agent.chat.']],
    ['label' => 'Rule Chat', 'icon' => '📜', 'route' => 'agent.rule-chat.index', 'match' => ['agent.rule-chat.']],
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

$agentName = $agent?->displayName;
$logoutUrl = $agent !== null ? $urlGenerator->generate('agent.logout') : null;
$csrfInput = $agent !== null ? (string) $csrf->hiddenInput() : '';

// Stores A–Z: mirrors the admin sidebar's Knowledge Bases index. The letters reuse the store list's own
// `?letter=` filter — no duplicate listing logic — and that listing is already restricted to stores this
// agent can actually chat with (canonical eligibility AND agent_enabled), so a letter can never surface an
// unavailable store. The group is expanded and its active letter highlighted while on the list itself.
$storesActive = $activeRoute === 'agent.home';

try {
    $storesBase = $urlGenerator->generate('agent.home');
} catch (Throwable) {
    $storesBase = null;
}
?>
<aside class="sidebar">
    <div class="sidebar__brand">
        <span class="sidebar__brand-mark">KF</span>
        <span>Agent Panel</span>
    </div>

    <div class="sidebar__section">Chat</div>
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

    <?php if ($storesBase !== null): ?>
        <details class="sidebar__kb" <?= $storesActive ? ' open' : '' ?>>
            <summary class="sidebar__link sidebar__kb-summary<?= $storesActive ? ' sidebar__link--active' : '' ?>">
                <span class="sidebar__link-icon" aria-hidden="true">🗂</span>
                <span>Stores</span>
                <span class="sidebar__kb-caret" aria-hidden="true">▾</span>
            </summary>
            <div class="sidebar__az" role="group" aria-label="Browse stores A to Z">
                <a class="sidebar__az-item<?= $storesActive && $activeLetter === AlphabetIndex::ALL ? ' sidebar__az-item--active' : '' ?>"
                    href="<?= Html::encode($storesBase) ?>">All</a>
                <?php foreach (AlphabetIndex::letters() as $letter): ?>
                    <a class="sidebar__az-item<?= $storesActive && $activeLetter === $letter ? ' sidebar__az-item--active' : '' ?>"
                        href="<?= Html::encode($storesBase . '?' . http_build_query(['letter' => $letter])) ?>"><?= Html::encode($letter) ?></a>
                <?php endforeach; ?>
            </div>
        </details>
    <?php endif; ?>

    <div class="sidebar__spacer"></div>

    <?php if ($agentName !== null && $logoutUrl !== null): ?>
        <div class="sidebar__user">
            <div class="sidebar__user-name"><?= Html::encode($agentName) ?></div>
            <div class="sidebar__user-role">Agent</div>
            <form class="sidebar__logout" method="post" action="<?= Html::encode($logoutUrl) ?>">
                <?= $csrfInput ?>
                <button type="submit" class="btn btn--secondary btn--sm btn--block">Sign out</button>
            </form>
        </div>
    <?php endif; ?>
</aside>