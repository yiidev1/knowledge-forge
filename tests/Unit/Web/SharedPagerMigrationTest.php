<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web;

use Codeception\Test\Unit;

use function file_get_contents;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;

/**
 * Migrated list templates must use the shared pager partial and must not keep the old Prev/Next-only markup.
 */
final class SharedPagerMigrationTest extends Unit
{
    /** @return list<string> */
    private function templates(): array
    {
        $root = dirname(__DIR__, 3);

        return [
            $root . '/src/Order58/Web/Stores/template.php',
            $root . '/src/Order58/Web/StoreChat/template.php',
            $root . '/src/Rules/Web/Readiness/template.php',
            $root . '/src/Rules/Web/RulesList/template.php',
            $root . '/src/Rules/Web/GlobalBase/template.php',
            $root . '/src/Agent/Web/Home/template.php',
        ];
    }

    public function testAllListTemplatesRenderSharedPagerPartial(): void
    {
        foreach ($this->templates() as $path) {
            $html = (string) file_get_contents($path);
            assertStringContainsString("Web/Shared/_partial/pager", $html, $path);
            assertStringContainsString('dirname(__DIR__, 3)', $html, $path);
            assertStringNotContainsString('style="opacity:.5;"', $html, $path);
            assertStringNotContainsString('style="margin-top: 1rem; display: flex; gap: .5rem; align-items: center;"', $html, $path);
        }
    }

    public function testSharedPagerPartialHasAccessibilityHooks(): void
    {
        $path = dirname(__DIR__, 3) . '/src/Web/Shared/_partial/pager.php';
        $html = (string) file_get_contents($path);

        assertStringContainsString('aria-label="Pagination"', $html);
        assertStringContainsString('aria-current="page"', $html);
        assertStringContainsString('pager__ellipsis', $html);
        assertStringContainsString('aria-disabled="true"', $html);
        assertStringContainsString('PageWindow::items', $html);
    }

    public function testChatHistoryStillUsesLoadOlderNotSharedPager(): void
    {
        $root = dirname(__DIR__, 3);
        foreach ([
            $root . '/src/Chat/Web/Index/template.php',
            $root . '/src/Agent/Web/Chat/template.php',
        ] as $path) {
            $html = (string) file_get_contents($path);
            assertStringContainsString('Load older', $html, $path);
            assertStringNotContainsString('Web/Shared/_partial/pager', $html, $path);
        }
    }
}
