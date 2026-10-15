<?php

declare(strict_types=1);

use App\Auth\Domain\AdminUser;
use App\Order58\Domain\StoreAudioFilter;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\Csrf;

/**
 * @var string $activeRoute
 * @var AdminUser|null $admin
 * @var \App\Agent\Domain\AgentIdentity|null $operator the signed-in Order58 operation user, if any
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
    // ['label' => 'Order58 orders', 'icon' => '🧾', 'route' => 'order58.orders', 'match' => ['order58.orders']],
    ['label' => 'Store chat', 'icon' => '💬', 'route' => 'order58.store-chat', 'match' => ['order58.store-chat']],
    ['label' => 'Rule Chat', 'icon' => '📜', 'route' => 'admin.rule-chat.index', 'match' => ['admin.rule-chat.']],
    ['label' => 'Rule list', 'icon' => '📋', 'route' => 'order58.rules.readiness', 'match' => ['order58.rules.readiness']],
    // Prefix is the whole `admin.reports.` namespace, so a future second report lights the same entry and
    // no sibling route lights this one.
    ['label' => 'Chat Reports', 'icon' => '📊', 'route' => 'admin.reports.chat', 'match' => ['admin.reports.']],
    // Straight to the store picker rather than through the `audio-to-text` redirect, because this entry
    // carries a query parameter and a redirect would drop it.
    //
    // `audio=with` is the LANDING STATE, and it belongs here rather than in the page: an administrator
    // arriving from this menu wants the stores that actually have recordings, and only a handful of the
    // mirrored stores ever do. Setting it on the page instead — "no filter means Uploaded audio" — was
    // tried and reverted, because every filter link omits a parameter that equals its own default, so
    // clicking "All stores" produced a bare URL that the page read straight back as Uploaded audio and
    // the click appeared to do nothing.
    //
    // A bare /admin/order58/store-audio therefore stays NEUTRAL. The menu establishes the initial state
    // once; every click after it is the user's.
    //
    // The match list covers both the picker's own route and the whole `audio-to-text` namespace (job,
    // list, status, download, review), so the entry stays lit across all of them — including the picker
    // itself, which it did not before.
    [
        'label' => 'Audio to Text',
        'icon' => '🎙',
        'route' => 'order58.store-audio',
        'query' => ['audio' => StoreAudioFilter::WithAudio->value],
        'match' => ['order58.store-audio', 'audio-to-text'],
    ],
    // Directly under Audio to Text, because it is the same recordings seen a different way: that page is
    // about converting audio, this one about working through the orders it belongs to. A separate entry
    // rather than a tab inside the other, so the two surfaces can diverge without the menu implying one
    // is a mode of the other. The match covers the whole namespace, so the entry stays lit on the store
    // page and its group endpoints as well as the landing list.
    [
        'label' => 'Manage Orders',
        'icon' => '🧪',
        // The route name as a literal, like every other entry here. This file is outside
        // `src/AudioToText/`, and ModuleIsolationTest forbids any file there from naming that module —
        // importing the constant would fail it.
        //
        // No `query` here, unlike the entry above: this page lands on Uploaded audio by itself, because
        // a store with nothing uploaded is not a place to test an order from. It can afford the default
        // the audio picker could not — its "All stores" chip emits an explicit `?audio=all`, so the
        // click that broke there widens the list here instead of appearing to do nothing.
        'route' => 'order-testing',
        'match' => ['order-testing'],
    ],
    // Beneath Order Testing, because it is the same source orders seen as training: the same stores,
    // the same recordings, the same demo results, with a topic attached and a narrower set of actions.
    // A separate entry rather than a mode of the other, so neither constrains the other's menu.
    [
        'label' => 'Practice Orders',
        'icon' => '🎓',
        'route' => 'practice-orders',
        'match' => ['practice-orders'],
    ],
    // Curating the topic list is administration, not training, so it sits on its own and is absent
    // from the operation allow-list below.
    [
        'label' => 'Topic Management',
        'icon' => '🏷',
        'route' => 'practice.topics',
        'match' => ['practice.topics'],
    ],
    // Its own entry rather than a link inside Audio to Text: the page is about Order58 calls, and the
    // prefix covers its two POST routes so they stay highlighted after a redirect.
    // ['label' => 'Order58 calls', 'icon' => '📞', 'route' => 'order58.calls', 'match' => ['order58.calls']],
    // Beneath it, and separate: the two pages do different jobs with the same provider. This one brings
    // audio in to be listened to; the one above brings it in to be transcribed. The prefix covers the
    // POST route so the entry stays lit after a redirect.
    [
        'label' => 'Call recordings',
        'icon' => '🎧',
        'route' => 'order58.call-recordings',
        'match' => ['order58.call-recordings'],
    ],
];

$navItems = [];
foreach ($items as $item) {
    try {
        // The third argument is query parameters, distinct from route placeholders. Built by the
        // generator rather than concatenated, so a value needing encoding is encoded once and correctly.
        $href = $urlGenerator->generate($item['route'], [], $item['query'] ?? []);
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

// An operation user sees only the entries they can actually reach. This is presentation only — every
// route in this list is also guarded server-side, and the ones absent from it are refused whether or
// not they are linked. Hiding a link has never been a permission; it is here so the page is honest
// about what is reachable.
//
// An allow-list rather than a single label, because that is now two entries: Order Testing and
// Practice Orders both sit behind the gate this realm passes. Topic Management deliberately does not —
// it is in the administrators' group, so linking it here would offer a door that answers with a
// redirect to a login form they cannot use.
$operatorMenu = ['Manage Orders', 'Practice Orders'];

if ($operator !== null) {
    $navItems = array_values(array_filter(
        $navItems,
        static fn(array $item): bool => in_array($item['label'], $operatorMenu, true),
    ));
}

// Each realm signs out through its own door. Sending an operation user to the administrator's logout
// would clear a session they never had and leave theirs intact.
$signedInName = $admin?->username() ?? $operator?->displayName;
$logoutRoute = $admin !== null ? 'auth.logout' : ($operator !== null ? 'agent.logout' : null);

$adminName = $signedInName;
$logoutUrl = $logoutRoute !== null ? $urlGenerator->generate($logoutRoute) : null;
$csrfInput = $logoutRoute !== null ? (string) $csrf->hiddenInput() : '';

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