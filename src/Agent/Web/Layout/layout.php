<?php

declare(strict_types=1);

use App\Agent\Application\CurrentAgent;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\AlphabetIndex;
use App\Web\Shared\Layout\Admin\AdminAsset;
use Yiisoft\Aliases\Aliases;
use Yiisoft\Html\Html;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\Csrf;

/**
 * @var \App\Shared\ApplicationParams $applicationParams
 * @var Aliases $aliases
 * @var Yiisoft\Assets\AssetManager $assetManager
 * @var string $content
 * @var Yiisoft\View\WebView $this
 * @var CurrentRoute $currentRoute
 * @var UrlGeneratorInterface $urlGenerator
 * @var CurrentAgent $currentAgent
 * @var FlashMessages $flash
 * @var Csrf $csrf
 */

$assetManager->register(AdminAsset::class);
$this->addCssFiles($assetManager->getCssFiles());
$this->addCssStrings($assetManager->getCssStrings());
$this->addJsFiles($assetManager->getJsFiles());

$agent = $currentAgent->getOrNull();
$activeRoute = $currentRoute->getName() ?? '';

// The active A–Z letter for the Stores sidebar (from the ?letter= query on the agent store list). Derived
// here rather than in the sidebar so the partial stays a pure renderer, matching the admin layout.
$activeLetter = AlphabetIndex::ALL;
$currentUri = $currentRoute->getUri();
if ($currentUri !== null) {
    parse_str($currentUri->getQuery(), $queryParams);
    $letterParam = $queryParams['letter'] ?? null;
    $activeLetter = AlphabetIndex::normalize(is_string($letterParam) ? $letterParam : null);
}

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
    <div class="app">
        <?= $this->render(__DIR__ . '/_sidebar', [
            'activeRoute' => $activeRoute,
            'activeLetter' => $activeLetter,
            'agent' => $agent,
            'urlGenerator' => $urlGenerator,
            'csrf' => $csrf,
        ]) ?>

        <div class="main">
            <div class="content">
                <?= $this->render($aliases->get('@src') . '/Web/Shared/Layout/Admin/_flash', ['messages' => $flashMessages]) ?>
                <?= $content ?>
            </div>
        </div>
    </div>
    <?php $this->endBody() ?>
</body>

</html>
<?php $this->endPage();
