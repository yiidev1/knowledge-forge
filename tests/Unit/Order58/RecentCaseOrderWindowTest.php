<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\Order58\Domain\RecentCaseOrderWindow;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * The Manage Orders and Practice Orders pickers list stores with a Case Order placed in the last 30 days:
 * a rolling 30 × 24 hours before now, in Unix seconds, so no time zone enters it.
 */
final class RecentCaseOrderWindowTest extends TestCase
{
    public function testTheWindowIsThirtyRollingDaysBeforeNow(): void
    {
        $now = new DateTimeImmutable('2026-11-05 13:43:42', new DateTimeZone('UTC'));

        self::assertSame(30, RecentCaseOrderWindow::DAYS);
        self::assertSame(
            (new DateTimeImmutable('2026-10-06 13:43:42', new DateTimeZone('UTC')))->getTimestamp(),
            RecentCaseOrderWindow::since($now),
        );
    }

    /** The same instant, however it is written, gives the same cutoff. */
    public function testTheTimeZoneTheInstantIsWrittenInDoesNotMoveTheCutoff(): void
    {
        $utc = new DateTimeImmutable('2026-11-05 13:43:42', new DateTimeZone('UTC'));
        $newYork = $utc->setTimezone(new DateTimeZone('America/New_York'));

        self::assertSame(RecentCaseOrderWindow::since($utc), RecentCaseOrderWindow::since($newYork));
    }
}
