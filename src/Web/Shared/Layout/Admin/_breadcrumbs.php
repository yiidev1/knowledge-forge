<?php

declare(strict_types=1);

use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * @var list<array{label: string, route?: string, arguments?: array<string, scalar|null>}> $breadcrumbs
 * @var UrlGeneratorInterface $urlGenerator
 */

// The last crumb is the current page and is never a link.
$lastIndex = count($breadcrumbs) - 1;

/**
 * Narrow a crumb's optional arguments to the scalar map the URL generator accepts. Breadcrumbs are
 * built internally, so this is a type guard rather than input sanitisation.
 *
 * @param array{arguments?: array<string, scalar|null>} $crumb
 *
 * @return array<string, scalar|null>
 */
$argumentsOf = static function (array $crumb): array {
    $arguments = [];
    foreach ($crumb['arguments'] ?? [] as $name => $value) {
        if (is_scalar($value) || $value === null) {
            $arguments[(string) $name] = $value;
        }
    }
    return $arguments;
};
?>
<nav class="breadcrumbs" aria-label="Breadcrumb">
    <?php if ($breadcrumbs === []): ?>
        <span class="breadcrumbs__current">Knowledge Forge</span>
    <?php else: ?>
        <?php foreach ($breadcrumbs as $index => $crumb): ?>
            <?php if ($index > 0): ?>
                <span class="breadcrumbs__sep" aria-hidden="true">/</span>
            <?php endif; ?>
            <?php if ($index < $lastIndex && isset($crumb['route'])): ?>
                <a href="<?= Html::encode($urlGenerator->generate($crumb['route'], $argumentsOf($crumb))) ?>">
                    <?= Html::encode($crumb['label']) ?>
                </a>
            <?php else: ?>
                <span class="breadcrumbs__current"><?= Html::encode($crumb['label']) ?></span>
            <?php endif; ?>
        <?php endforeach; ?>
    <?php endif; ?>
</nav>
