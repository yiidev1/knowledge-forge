<?php

declare(strict_types=1);

use App\Auth\Application\CurrentAdmin;
use App\Shared\Web\Flash\FlashMessages;
use App\Web\Shared\Layout\Admin\AdminAsset;
use Yiisoft\Html\Html;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\Csrf;

/**
 * @var \App\Shared\ApplicationParams $applicationParams
 * @var Yiisoft\Aliases\Aliases $aliases
 * @var Yiisoft\Assets\AssetManager $assetManager
 * @var string $content
 * @var Yiisoft\View\WebView $this
 * @var CurrentRoute $currentRoute
 * @var UrlGeneratorInterface $urlGenerator
 * @var CurrentAdmin $currentAdmin
 * @var FlashMessages $flash
 * @var Csrf $csrf
 */

$assetManager->register(AdminAsset::class);
$this->addCssFiles($assetManager->getCssFiles());
$this->addCssStrings($assetManager->getCssStrings());
$this->addJsFiles($assetManager->getJsFiles());

$activeRoute = $currentRoute->getName() ?? '';
$admin = $currentAdmin->getOrNull();

/** @var list<array{level: string, message: string}> $flashMessages */
$flashMessages = $flash->consume();

/** @var list<array{label: string, route?: string}> $breadcrumbs */
$breadcrumbs = $this->hasParameter('breadcrumbs') ? (array) $this->getParameter('breadcrumbs') : [];

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
<div class="app">
    <?= $this->render(__DIR__ . '/_sidebar', [
        'activeRoute' => $activeRoute,
        'admin' => $admin,
        'urlGenerator' => $urlGenerator,
        'applicationParams' => $applicationParams,
        'csrf' => $csrf,
    ]) ?>

    <div class="main">
        <div class="topbar">
            <div class="topbar__inner">
                <?= $this->render(__DIR__ . '/_breadcrumbs', [
                    'breadcrumbs' => $breadcrumbs,
                    'urlGenerator' => $urlGenerator,
                ]) ?>
            </div>
        </div>

        <div class="content">
            <?= $this->render(__DIR__ . '/_flash', ['messages' => $flashMessages]) ?>
            <?= $content ?>
        </div>
    </div>
</div>
<?php $this->endBody() ?>
</body>
</html>
<?php $this->endPage();
