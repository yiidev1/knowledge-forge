<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\Application\Usage\UsageStoreRow;
use App\Ai\OpenAi\Dto\VectorStoreFileCounts;
use App\Ai\Web\Usage\UsageSort;
use Codeception\Test\Unit;

use function array_map;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * Server-side table ordering, including the rejection of anything unrecognised in the query string.
 */
final class UsageSortTest extends Unit
{
    public function testDefaultsToNameAscending(): void
    {
        $sort = UsageSort::fromQuery(null, null);

        assertSame('name', $sort->field);
        assertFalse($sort->descending);
        assertSame('asc', $sort->direction());
    }

    /**
     * A crafted value must not reach the page. It is replaced by the default, and because the template
     * only ever echoes the validated field, it cannot be reflected into the HTML.
     */
    public function testUnknownFieldFallsBackToTheDefault(): void
    {
        assertSame('name', UsageSort::fromQuery('<script>alert(1)</script>', 'desc')->field);
        assertSame('name', UsageSort::fromQuery('; DROP TABLE', 'asc')->field);
        assertSame('name', UsageSort::fromQuery(['array'], null)->field);
        assertSame('name', UsageSort::fromQuery(123, null)->field);
    }

    public function testUnknownDirectionIsAscending(): void
    {
        assertFalse(UsageSort::fromQuery('name', 'sideways')->descending);
        assertTrue(UsageSort::fromQuery('name', 'desc')->descending);
    }

    public function testSortsByStorage(): void
    {
        $rows = [$this->row('b', 300), $this->row('a', 100), $this->row('c', 200)];

        $sorted = UsageSort::fromQuery('storage', 'asc')->apply($rows);

        assertSame([100, 200, 300], array_map(static fn(UsageStoreRow $r): int => $r->usageBytes, $sorted));
    }

    public function testDescendingReversesTheOrder(): void
    {
        $rows = [$this->row('b', 300), $this->row('a', 100), $this->row('c', 200)];

        $sorted = UsageSort::fromQuery('storage', 'desc')->apply($rows);

        assertSame([300, 200, 100], array_map(static fn(UsageStoreRow $r): int => $r->usageBytes, $sorted));
    }

    /**
     * "Never expires" belongs at the far end of the scale. Treating a null expiry as 0 would rank
     * permanent stores as the most urgent.
     */
    public function testStoresWithoutAnExpirySortLast(): void
    {
        $rows = [
            $this->row('never', 1, null),
            $this->row('soon', 1, 1785000000),
        ];

        $sorted = UsageSort::fromQuery('expires', 'asc')->apply($rows);

        assertSame('soon', $sorted[0]->name);
        assertSame('never', $sorted[1]->name);
    }

    /**
     * A header link flips only its own column; every other column starts ascending.
     */
    public function testHeaderLinksFlipOnlyTheActiveColumn(): void
    {
        $sort = UsageSort::fromQuery('storage', 'asc');

        assertSame('desc', $sort->nextDirectionFor('storage'));
        assertSame('asc', $sort->nextDirectionFor('name'));

        $flipped = UsageSort::fromQuery('storage', 'desc');
        assertSame('asc', $flipped->nextDirectionFor('storage'));
    }

    public function testAriaSortMarksOnlyTheActiveColumn(): void
    {
        $sort = UsageSort::fromQuery('storage', 'desc');

        assertSame('descending', $sort->ariaFor('storage'));
        assertSame('none', $sort->ariaFor('name'));
        assertSame('ascending', UsageSort::fromQuery('storage', 'asc')->ariaFor('storage'));
    }

    private function row(string $name, int $bytes, ?int $expiresAt = null): UsageStoreRow
    {
        return new UsageStoreRow(
            id: 'vs_' . $name,
            name: $name,
            status: 'completed',
            usageBytes: $bytes,
            fileCounts: VectorStoreFileCounts::zero(),
            createdAt: null,
            lastActiveAt: null,
            expiresAt: $expiresAt,
        );
    }
}
