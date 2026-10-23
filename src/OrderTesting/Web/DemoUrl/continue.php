<?php

declare(strict_types=1);

use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * The no-JavaScript path out to Order58.
 *
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var array<string, string> $routes this surface's own addresses — never Manage Orders' on the
 *                                    agent practice surface, whose gate refuses them
 * @var int $sourceId
 * @var int $sourceOrderId
 * @var string $url the Order58 address, built server-side and host-checked. Never from the request.
 */

$this->setTitle('Opening Order58');
$this->setParameter('breadcrumbs', [
    ['label' => $routes['storeLabel'], 'route' => $routes['page']],
    ['label' => 'Opening Order58'],
]);

$storeUrl = $urlGenerator->generate($routes['store'], ['sourceId' => $sourceId]);
?>

<div class="page-header">
    <div>
        <h1 class="page-header__title">Test recorded &mdash; continue to Order58</h1>
        <p class="page-header__subtitle">
            Your test of order #<?= Html::encode((string) $sourceOrderId) ?> has been recorded against
            your account. Open Order58 to create the demo order.
        </p>
    </div>
</div>

<div class="card">
    <?php
    // A plain link, and that is the point of this page.
    //
    // The browser arrived here by submitting a form, and `form-action 'self'` is enforced across
    // redirects — so this request could not have been answered with a redirect to Order58. A link is
    // not a form submission, so following it is permitted. The page script normally spares anyone
    // this step; this is what happens when it did not load.
    //
    // `noreferrer` is not decoration: the destination carries a customer's phone number in its path,
    // and without it the browser would hand this page's URL to Order58 as the Referer.
?>
    <p>
        <a class="btn btn--primary" href="<?= Html::encode($url) ?>"
           target="_blank" rel="noopener noreferrer">Open the Order58 demo page</a>
        <a class="btn" href="<?= Html::encode($storeUrl) ?>">Back to
            <?= Html::encode($routes['storeLabel']) ?></a>
    </p>
    <p class="util-muted">
        <?php
        // It no longer says the order is "held for you". It is not: several people may practise the
        // same source order at once, each with their own tracked attempt. What is still true is that
        // pressing again inside the window resumes this attempt rather than starting another.
?>
        Your attempt is recorded and stays open for a while. Pressing Practice Order again before it
        expires continues this one rather than starting a new test.
    </p>
</div>
