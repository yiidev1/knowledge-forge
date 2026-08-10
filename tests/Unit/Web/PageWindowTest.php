<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web;

use App\Web\Shared\Pagination\PageWindow;
use Codeception\Test\Unit;

use function array_key_last;
use function in_array;
use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotContains;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final class PageWindowTest extends Unit
{
    public function testPageCountOneYieldsEmptyWindow(): void
    {
        assertSame([], PageWindow::items(1, 1));
        assertSame([], PageWindow::items(1, 0));
    }

    public function testPageCountTwoShowsBothPages(): void
    {
        assertSame([1, 2], PageWindow::items(1, 2));
        assertSame([1, 2], PageWindow::items(2, 2));
    }

    public function testSmallPageCountShowsAllWithoutEllipsis(): void
    {
        assertSame([1, 2, 3, 4, 5, 6, 7], PageWindow::items(4, 7));
        assertNotContains(null, PageWindow::items(1, PageWindow::SMALL_THRESHOLD));
    }

    public function testFirstPageOfLargeSet(): void
    {
        $items = PageWindow::items(1, 136);

        assertSame(1, $items[0]);
        assertContains(2, $items);
        assertContains(6, $items);
        assertContains(null, $items);
        assertSame(136, $items[array_key_last($items)]);
        assertFalse(in_array(7, $items, true));
    }

    public function testMiddlePageOfLargeSet(): void
    {
        $items = PageWindow::items(66, 136);

        assertSame([1, null, 64, 65, 66, 67, 68, null, 136], $items);
    }

    public function testLastPageOfLargeSet(): void
    {
        $items = PageWindow::items(136, 136);

        assertSame(1, $items[0]);
        assertContains(null, $items);
        assertContains(131, $items);
        assertContains(135, $items);
        assertSame(136, $items[array_key_last($items)]);
    }

    public function testClampHandlesInvalidInput(): void
    {
        assertSame(1, PageWindow::clamp(0, 10));
        assertSame(1, PageWindow::clamp(-5, 10));
        assertSame(10, PageWindow::clamp(99, 10));
        assertSame(1, PageWindow::clamp(5, 0));
        assertSame(3, PageWindow::clamp(3, 10));
    }

    public function testCurrentPageIsAlwaysPresentInWindow(): void
    {
        foreach ([1, 2, 7, 50, 135, 136] as $current) {
            $items = PageWindow::items($current, 136);
            assertTrue(in_array($current, $items, true), "current page {$current} missing");
        }
    }

    public function testPreviousDisabledSemanticsOnFirstPage(): void
    {
        $page = PageWindow::clamp(1, 20);
        assertTrue($page <= 1);
        assertFalse($page >= 20);
    }

    public function testNextDisabledSemanticsOnLastPage(): void
    {
        $page = PageWindow::clamp(20, 20);
        assertTrue($page >= 20);
        assertFalse($page <= 1);
    }
}
