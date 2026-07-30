<?php

declare(strict_types=1);

use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\Csrf;

/**
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var Csrf $csrf
 * @var string $username Previously submitted username, redisplayed so a mistyped password does not
 *                       force the whole form to be re-entered. Never the password.
 */

$this->setTitle('Sign in');
?>
<h1 class="auth__title">Sign in</h1>
<p class="auth__subtitle">Administrator access</p>

<form method="post" action="<?= Html::encode($urlGenerator->generate('auth.login')) ?>" novalidate>
    <?= $csrf->hiddenInput() ?>

    <div class="field">
        <label class="field__label" for="username">Username</label>
        <input
            class="field__control"
            type="text"
            id="username"
            name="username"
            value="<?= Html::encode($username) ?>"
            autocomplete="username"
            autofocus
            required
        >
    </div>

    <div class="field">
        <label class="field__label" for="password">Password</label>
        <input
            class="field__control"
            type="password"
            id="password"
            name="password"
            autocomplete="current-password"
            required
        >
    </div>

    <button type="submit" class="btn btn--primary btn--block">Sign in</button>
</form>
