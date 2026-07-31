<?php

declare(strict_types=1);

use App\Agent\Domain\AgentIdentity;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\Csrf;

/**
 * @var string $activeRoute
 * @var AgentIdentity|null $agent
 * @var UrlGeneratorInterface $urlGenerator
 * @var Csrf $csrf
 * @var Yiisoft\View\WebView $this
 */

// The agent realm is intentionally small: a single "Store List" entry (its chat lives under it). Kept as a
// list so it reads like the admin sidebar and can grow if the agent gains more pages.
$items = [
    ['label' => 'Store List', 'icon' => '🏬', 'route' => 'agent.home', 'match' => ['agent.home', 'agent.chat.']],
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