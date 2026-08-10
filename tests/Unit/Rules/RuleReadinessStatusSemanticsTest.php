<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rules;

use App\Rules\Domain\RuleReadinessFilter;
use App\Rules\Domain\RuleReadinessStatus;
use App\Rules\Domain\RuleReadinessSummary;
use Codeception\Test\Unit;

use function in_array;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final class RuleReadinessStatusSemanticsTest extends Unit
{
    public function testSummaryTotalCountsSyncedRulesNotOnlyDocuments(): void
    {
        $summary = RuleReadinessSummary::fromCounts([
            'ready' => 0,
            'inactive' => 375,
            'not_materialized' => 0,
        ]);

        assertSame(375, $summary->total());
        assertSame(0, $summary->ready);
        assertSame(375, $summary->disabledOrInactive());
        assertSame(0, $summary->pending());
    }

    public function testDisabledFilterIncludesInactive(): void
    {
        $statuses = RuleReadinessFilter::Disabled->statuses();
        assertTrue(in_array(RuleReadinessStatus::Disabled->value, $statuses, true));
        assertTrue(in_array(RuleReadinessStatus::Inactive->value, $statuses, true));
    }

    public function testNotMaterializedIsSeparateFromPending(): void
    {
        assertSame(
            [RuleReadinessStatus::NotMaterialized->value],
            RuleReadinessFilter::NotMaterialized->statuses(),
        );
        assertTrue(!in_array(
            RuleReadinessStatus::NotMaterialized->value,
            RuleReadinessFilter::Pending->statuses(),
            true,
        ));
    }
}
