<?php

declare(strict_types=1);

use Yiisoft\Html\Html;

/**
 * @var list<array{level: string, message: string}> $messages
 */

// Map application flash levels to alert styles and a leading glyph. An unknown level degrades to info
// rather than rendering unstyled.
$icons = ['success' => '✓', 'error' => '✕', 'warning' => '!', 'info' => 'i'];
$levels = ['success', 'error', 'warning', 'info'];
?>
<?php if ($messages !== []): ?>
    <div class="flash-stack">
        <?php foreach ($messages as $message): ?>
            <?php $level = in_array($message['level'], $levels, true) ? $message['level'] : 'info'; ?>
            <div class="alert alert--<?= Html::encode($level) ?>" role="alert">
                <span class="alert__icon" aria-hidden="true"><?= Html::encode($icons[$level]) ?></span>
                <span><?= Html::encode($message['message']) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
