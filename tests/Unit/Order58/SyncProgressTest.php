<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\Order58\Domain\SyncProgress;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertSame;

/**
 * The progress/cursor is persisted as JSON and restored, so a resumed run continues from the right page.
 */
final class SyncProgressTest extends Unit
{
    public function testRoundTripsThroughArray(): void
    {
        $progress = new SyncProgress(nextPage: 4, pagesProcessed: 3, created: 5, skippedMissingStore: 2, warnings: 2);

        $restored = SyncProgress::fromArray($progress->toArray());

        assertSame(4, $restored->nextPage);
        assertSame(3, $restored->pagesProcessed);
        assertSame(5, $restored->created);
        assertSame(2, $restored->skippedMissingStore);
        assertSame(2, $restored->warnings);
    }

    public function testDefaultsToPageOne(): void
    {
        assertSame(1, SyncProgress::fromArray(null)->nextPage);
        assertSame(1, SyncProgress::fromArray([])->nextPage);
        assertSame(0, SyncProgress::fromArray([])->created);
    }
}
