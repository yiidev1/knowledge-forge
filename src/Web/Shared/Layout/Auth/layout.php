<?php

declare(strict_types=1);

use App\Shared\Web\Flash\FlashMessages;
use App\Web\Shared\Layout\Admin\AdminAsset;
use Yiisoft\Html\Html;

/**
 * Minimal centred layout for the login screen — no sidebar, no authenticated chrome.
 *
 * @var \App\Shared\ApplicationParams $applicationParams
 * @var Yiisoft\Aliases\Aliases $aliases
 * @var Yiisoft\Assets\AssetManager $assetManager
 * @var string $content
 * @var Yiisoft\View\WebView $this
 * @var FlashMessages $flash
 */

$assetManager->register(AdminAsset::class);
$this->addCssFiles($assetManager->getCssFiles());
$this->addCssStrings($assetManager->getCssStrings());

/** @var list<array{level: string, message: string}> $flashMessages */
$flashMessages = $flash->consume();

$this->beginPage();
?>
<!DOCTYPE html>
<html lang="<?= Html::encode($applicationParams->locale) ?>">
<head>
    <meta charset="<?= Html::encode($applicationParams->charset) ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="<?= Html::encode($aliases->get('@baseUrl/favicon.svg')) ?>" type="image/svg+xml">
    <title><?= Html::encode($this->getTitle()) ?></title>
    <?php $this->head() ?>
</head>
<body>
<?php $this->beginBody() ?>
<div class="auth">
    <div class="auth__card">
        <div class="auth__brand">
            <span class="sidebar__brand-mark">KF</span>
            <span><?= Html::encode($applicationParams->name) ?></span>
        </div>
        <?= $this->render(dirname(__DIR__) . '/Admin/_flash', ['messages' => $flashMessages]) ?>
        <?= $content ?>
    </div>
</div>
<?php $this->endBody() ?>
</body>
</html>
<?php $this->endPage();
