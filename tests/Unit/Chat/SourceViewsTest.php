<?php

declare(strict_types=1);

namespace App\Tests\Unit\Chat;

use App\Chat\Web\Sources\SourceViews;
use PHPUnit\Framework\TestCase;

/**
 * Guards the bug that took the agent source pages down: they passed a `@src/...` alias as the view name while
 * setting only a layout, so {@see \Yiisoft\Yii\View\Renderer\WebViewRenderer} had no view path to resolve
 * against and threw "The view path is not set." at request time.
 *
 * The renderer treats a view name as already-resolved only when it is an absolute path, so every shared
 * template path must be absolute AND point at a file that exists. Both realms now read these from one place,
 * which is what this test pins.
 */
final class SourceViewsTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function viewProvider(): array
    {
        return [
            'knowledge' => [SourceViews::knowledge()],
            'store rules' => [SourceViews::storeRules()],
            'rule chat rules' => [SourceViews::ruleChatRules()],
        ];
    }

    /**
     * @dataProvider viewProvider
     */
    public function testViewPathIsAbsolute(string $path): void
    {
        self::assertStringStartsWith('/', $path, 'A relative view name makes the renderer need a view path.');
    }

    /**
     * @dataProvider viewProvider
     */
    public function testViewFileExists(string $path): void
    {
        self::assertFileExists($path . '.php');
    }
}
