<?php

declare(strict_types=1);

namespace App\Tests\Unit\OrderTesting;

use App\OrderTesting\Application\Comparison\ComparisonRules;
use App\OrderTesting\Application\Comparison\ItemMatcher;
use App\OrderTesting\Domain\Comparison\NormalizedItem;
use PHPUnit\Framework\TestCase;

use function array_fill;
use function count;
use function microtime;
use function sprintf;
use function usort;

/**
 * The pairing of duplicate lines of one product, as an assignment problem.
 *
 * ## What is really being guarded
 *
 * That the pairing is **optimal**, not merely plausible. Every avoidable mis-pairing is a difference
 * reported against a trainee who did not make it, so "close enough" here has a victim.
 *
 * The matcher used to search exhaustively up to six lines of one product and fall back to a greedy pass
 * above that. These tests pin the thing that fixed: {@see greedyTotal()} is that old rule, kept here as
 * a reference, and {@see OPTIMAL_INSTANCE} is a real nine-line instance on which it is five
 * facet-differences worse than the optimum. If the matcher ever regresses to an approximation, the
 * first test fails with the gap spelled out.
 */
final class ItemMatcherTest extends TestCase
{
    /**
     * A nine-line group on which the old greedy rule costs 19 and the optimum is 14.
     *
     * Found by exhaustive search over randomised groups, then frozen here. Each tuple is one line's
     * four comparable facets — quantity, unit price, modifier set, instructions — as small integers;
     * {@see item()} turns one into a line. The cost of pairing two lines is how many of the four
     * differ, so these integers are the whole problem.
     */
    private const ORIGINALS = [
        [0, 1, 1, 1], [1, 0, 1, 0], [1, 1, 1, 0], [0, 1, 0, 2], [1, 1, 1, 2],
        [0, 1, 2, 0], [1, 1, 1, 2], [2, 0, 1, 0], [2, 0, 0, 1],
    ];

    private const DEMOS = [
        [2, 0, 1, 2], [0, 0, 1, 2], [0, 0, 2, 0], [1, 2, 1, 0], [2, 0, 2, 0],
        [2, 1, 0, 1], [0, 0, 2, 2], [2, 0, 0, 0], [0, 0, 0, 1],
    ];

    // ------------------------------------------------------------------ optimality

    /**
     * Nine duplicate lines: the old greedy rule reports five differences that do not exist.
     *
     * This is the whole case for replacing it. The assertion is on the *total* rather than on a
     * particular pairing, because any minimum-cost pairing is an acceptable answer and there is usually
     * more than one.
     */
    public function testSevenPlusDuplicatesBeatTheGreedyRuleThisReplaced(): void
    {
        $originals = $this->items(self::ORIGINALS);
        $demos = $this->items(self::DEMOS);

        $matched = $this->totalCost((new ItemMatcher())->pair($originals, $demos));
        $greedy = $this->greedyTotal($originals, $demos);
        $optimal = $this->optimalTotal($originals, $demos);

        $this->assertSame(14, $optimal, 'The frozen instance has a known optimum.');
        $this->assertSame(19, $greedy, 'And the rule this replaced reaches 19 on it.');
        $this->assertSame(
            $optimal,
            $matched,
            sprintf('The matcher must reach the optimum %d, not the greedy %d.', $optimal, $greedy),
        );
        $this->assertLessThan($greedy, $matched, 'Five differences that would have been invented.');
    }

    /**
     * Optimal on every generated group, not just the frozen one.
     *
     * Deterministically generated — a fixed seed and an open-coded generator — so a failure is
     * reproducible rather than a story about a run nobody can repeat.
     *
     * @dataProvider duplicateGroupSizes
     */
    public function testThePairingIsOptimalForGroupsAboveTheOldExhaustiveLimit(int $size): void
    {
        $seed = 20261009;

        for ($trial = 0; $trial < 40; ++$trial) {
            [$originals, $seed] = $this->generate($size, $seed);
            [$demos, $seed] = $this->generate($size, $seed);

            $matched = $this->totalCost((new ItemMatcher())->pair($originals, $demos));

            $this->assertSame(
                $this->optimalTotal($originals, $demos),
                $matched,
                sprintf('Group of %d, trial %d: the pairing was not minimum-cost.', $size, $trial),
            );
        }
    }

    /** @return iterable<string, array{int}> */
    public static function duplicateGroupSizes(): iterable
    {
        // 7 is the first size the old implementation did not handle exactly.
        foreach ([7, 8, 9, 10] as $size) {
            yield $size . ' duplicate lines' => [$size];
        }
    }

    /** Never worse than the rule it replaced, on any generated group. */
    public function testTheMatcherIsNeverWorseThanTheGreedyRule(): void
    {
        $seed = 991;
        $strictlyBetter = 0;

        for ($trial = 0; $trial < 60; ++$trial) {
            [$originals, $seed] = $this->generate(8, $seed);
            [$demos, $seed] = $this->generate(8, $seed);

            $matched = $this->totalCost((new ItemMatcher())->pair($originals, $demos));
            $greedy = $this->greedyTotal($originals, $demos);

            $this->assertLessThanOrEqual($greedy, $matched);

            if ($matched < $greedy) {
                ++$strictlyBetter;
            }
        }

        $this->assertGreaterThan(
            0,
            $strictlyBetter,
            'If greedy were never worse here, this replacement would not be worth its code.',
        );
    }

    // ------------------------------------------------------------------ bounds and determinism

    /**
     * A pathological group still completes, and still produces a valid matching.
     *
     * No timing assertion — those fail on a loaded machine for reasons that have nothing to do with
     * the code. What is asserted is what the old factorial search could not promise at all: that a
     * group far larger than any real order terminates and pairs every line exactly once.
     */
    public function testALargeGroupTerminatesWithAValidMatching(): void
    {
        $seed = 7;
        [$originals, $seed] = $this->generate(80, $seed);
        [$demos] = $this->generate(80, $seed);

        $started = microtime(true);
        $rows = (new ItemMatcher())->pair($originals, $demos);
        $elapsed = microtime(true) - $started;

        $this->assertCount(80, $rows, 'Every line paired, none duplicated and none dropped.');

        $seenDemos = [];
        foreach ($rows as $row) {
            $this->assertNotNull($row['original']);
            $this->assertNotNull($row['demo']);
            $key = $row['demo']->instructions . '|' . $row['demo']->quantityText
                . '|' . $row['demo']->unitPrice . '|' . $row['demo']->modifierSignature;
            $seenDemos[] = $key;
        }

        $this->assertCount(80, $seenDemos);
        // Generous by two orders of magnitude: this is a smoke alarm, not a benchmark.
        $this->assertLessThan(10.0, $elapsed, 'A bounded algorithm must not take seconds.');
    }

    /** Same input, same pairing — a reviewer must not see the page change under them. */
    public function testThePairingIsReproducible(): void
    {
        $originals = $this->items(self::ORIGINALS);
        $demos = $this->items(self::DEMOS);

        $first = (new ItemMatcher())->pair($originals, $demos);
        $second = (new ItemMatcher())->pair($originals, $demos);

        $this->assertSame($this->shape($first), $this->shape($second));
    }

    /** Equal-cost pairings resolve toward the earlier demo line, which is the documented tie-break. */
    public function testAnEqualCostTieResolvesTowardTheEarlierDemoLine(): void
    {
        // One original, two interchangeable demos: both cost the same, so the first must win.
        $original = $this->item([0, 0, 0, 0]);
        $demos = [$this->item([0, 0, 0, 0]), $this->item([0, 0, 0, 0])];

        $rows = (new ItemMatcher())->pair([$original], $demos);

        $this->assertSame($demos[0], $rows[0]['demo']);
        $this->assertNull($rows[1]['original'], 'The second demo is the unpaired extra.');
    }

    /** More originals than demos, and the reverse: both orientations pair as many as possible. */
    public function testUnequalGroupSizesPairAsManyLinesAsPossible(): void
    {
        $three = $this->items([[0, 0, 0, 0], [1, 1, 1, 1], [2, 2, 2, 2]]);
        $two = $this->items([[1, 1, 1, 1], [0, 0, 0, 0]]);

        $tallRows = (new ItemMatcher())->pair($three, $two);
        $wideRows = (new ItemMatcher())->pair($two, $three);

        $this->assertSame(2, $this->pairedCount($tallRows), 'Two pairs and one line missing in demo.');
        $this->assertSame(2, $this->pairedCount($wideRows), 'Two pairs and one extra in demo.');
        // Both orientations find the zero-cost pairing rather than the positional one.
        $this->assertSame(0, $this->totalCost($tallRows));
        $this->assertSame(0, $this->totalCost($wideRows));
    }

    // ------------------------------------------------------------------ harness

    /**
     * The greedy rule this replaced: cheapest pair first, ties by original then demo index.
     *
     * Kept so the improvement is measured against the real previous behaviour rather than against a
     * description of it.
     *
     * @param list<NormalizedItem> $originals
     * @param list<NormalizedItem> $demos
     */
    private function greedyTotal(array $originals, array $demos): int
    {
        $candidates = [];

        foreach ($originals as $o => $original) {
            foreach ($demos as $d => $demo) {
                $candidates[] = [$this->cost($original, $demo), $o, $d];
            }
        }

        usort($candidates, static fn(array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

        $usedOriginals = [];
        $usedDemos = [];
        $total = 0;

        foreach ($candidates as [$cost, $o, $d]) {
            if (isset($usedOriginals[$o]) || isset($usedDemos[$d])) {
                continue;
            }

            $usedOriginals[$o] = true;
            $usedDemos[$d] = true;
            $total += $cost;
        }

        return $total;
    }

    /**
     * The true minimum, by dynamic programming over subsets — independent of the implementation.
     *
     * @param list<NormalizedItem> $originals
     * @param list<NormalizedItem> $demos
     */
    private function optimalTotal(array $originals, array $demos): int
    {
        $rows = count($originals);
        $columns = count($demos);
        $states = 1 << $columns;
        $best = array_fill(0, $states, PHP_INT_MAX);
        $best[0] = 0;

        for ($mask = 0; $mask < $states; ++$mask) {
            if ($best[$mask] === PHP_INT_MAX) {
                continue;
            }

            $row = 0;
            for ($bit = 0; $bit < $columns; ++$bit) {
                $row += ($mask >> $bit) & 1;
            }

            if ($row >= $rows) {
                continue;
            }

            for ($column = 0; $column < $columns; ++$column) {
                if (($mask >> $column) & 1) {
                    continue;
                }

                $next = $mask | (1 << $column);
                $candidate = $best[$mask] + $this->cost($originals[$row], $demos[$column]);

                if ($candidate < $best[$next]) {
                    $best[$next] = $candidate;
                }
            }
        }

        $minimum = PHP_INT_MAX;
        $pairs = $rows < $columns ? $rows : $columns;

        for ($mask = 0; $mask < $states; ++$mask) {
            $bits = 0;
            for ($bit = 0; $bit < $columns; ++$bit) {
                $bits += ($mask >> $bit) & 1;
            }

            if ($bits === $pairs && $best[$mask] < $minimum) {
                $minimum = $best[$mask];
            }
        }

        return $minimum;
    }

    /** @param list<array{original: ?NormalizedItem, demo: ?NormalizedItem}> $rows */
    private function totalCost(array $rows): int
    {
        $total = 0;

        foreach ($rows as $row) {
            if ($row['original'] !== null && $row['demo'] !== null) {
                $total += $this->cost($row['original'], $row['demo']);
            }
        }

        return $total;
    }

    /** @param list<array{original: ?NormalizedItem, demo: ?NormalizedItem}> $rows */
    private function pairedCount(array $rows): int
    {
        $paired = 0;

        foreach ($rows as $row) {
            if ($row['original'] !== null && $row['demo'] !== null) {
                ++$paired;
            }
        }

        return $paired;
    }

    /**
     * @param list<array{original: ?NormalizedItem, demo: ?NormalizedItem}> $rows
     *
     * @return list<string>
     */
    private function shape(array $rows): array
    {
        $shape = [];

        foreach ($rows as $row) {
            $shape[] = ($row['original']?->instructions ?? '-') . '>' . ($row['demo']?->instructions ?? '-');
        }

        return $shape;
    }

    /** The same facet-count cost the matcher uses, re-derived here rather than borrowed. */
    private function cost(NormalizedItem $original, NormalizedItem $demo): int
    {
        $left = $original->facets();
        $right = $demo->facets();
        $cost = 0;

        foreach ($left as $facet => $value) {
            $other = $right[$facet] ?? '';

            $same = match ($facet) {
                'qty', 'unitPrice' => ComparisonRules::moneyEquals($value, $other),
                default => ComparisonRules::textEquals($value, $other),
            };

            if (!$same) {
                ++$cost;
            }
        }

        return $cost;
    }

    /**
     * A deterministic generator, so a failing case can be reproduced exactly.
     *
     * A linear congruential step rather than `mt_rand`: the sequence is part of the test, and a global
     * generator somebody else seeds is not.
     *
     * @return array{list<NormalizedItem>, int}
     */
    private function generate(int $size, int $seed): array
    {
        $items = [];

        for ($i = 0; $i < $size; ++$i) {
            $facets = [];

            for ($facet = 0; $facet < 4; ++$facet) {
                $seed = ($seed * 1103515245 + 12345) & 0x7FFFFFFF;
                $facets[] = ($seed >> 16) % 3;
            }

            $items[] = $this->item($facets);
        }

        return [$items, $seed];
    }

    /**
     * One line from four small integers, each picking a value for one comparable facet.
     *
     * All lines share a product id and name, so they form a single duplicate-product group — which is
     * the situation the assignment exists for.
     *
     * @param list<int> $facets
     */
    private function item(array $facets): NormalizedItem
    {
        $quantities = ['1', '2', '3'];
        $prices = ['1.00000', '2.00000', '3.00000'];
        $modifiers = ['', 'A|Sauce|Hot||', 'A|Sauce|Mild||'];
        $instructions = [null, 'well done', 'no salt'];

        return new NormalizedItem(
            name: '[F7]Fried Chicken Wings (4)',
            sn: 'F7',
            quantity: null,
            unitPrice: $prices[$facets[1]],
            total: null,
            options: [],
            instructions: $instructions[$facets[3]],
            productId: 506941,
            productName: 'Fried Chicken Wings (4)',
            quantityText: $quantities[$facets[0]],
            modifierSignature: $modifiers[$facets[2]],
        );
    }

    /**
     * @param list<list<int>> $rows
     *
     * @return list<NormalizedItem>
     */
    private function items(array $rows): array
    {
        $items = [];

        foreach ($rows as $row) {
            $items[] = $this->item($row);
        }

        return $items;
    }
}
