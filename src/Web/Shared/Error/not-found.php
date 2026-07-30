<?php

declare(strict_types=1);

use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 */

$this->setTitle('Not found');
$dashboardUrl = $urlGenerator->generate('dashboard');
?>
<div class="empty" style="margin-top: 3rem;">
    <div class="empty__icon" aria-hidden="true">🔍</div>
    <div class="empty__title">Not found</div>
    <p>The page or resource you requested does not exist, or you do not have access to it.</p>
    <p><a class="btn btn--secondary" href="<?= Html::encode($dashboardUrl) ?>">Back to dashboard</a></p>
</div>
