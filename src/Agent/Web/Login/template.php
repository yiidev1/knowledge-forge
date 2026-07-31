<?php

declare(strict_types=1);

use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\Csrf;

/**
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var Csrf $csrf
 * @var string $username
 */

$this->setTitle('Agent sign in');
?>
<h1 class="auth__title">Agent sign in</h1>
<p class="auth__subtitle">Order58 agent access</p>

<form method="post" action="<?= Html::encode($urlGenerator->generate('agent.login')) ?>" novalidate>
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
