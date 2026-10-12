<?php

declare(strict_types=1);

namespace App\OrderTesting\Application\Comparison;

use App\OrderTesting\Domain\Comparison\NormalizedItem;

use function array_fill;
use function count;
use function ksort;

use const PHP_INT_MAX;

/**
 * Decides which original line corresponds to which demo line.
 *
 * ## Why the obvious implementation is wrong
 *
 * First-unclaimed-line-with-the-same-name pairs in document order, which is fine until an order
 * contains the same product twice. Two Fried Chicken Wings, one plain and one with extra sauce: if the
 * trainee entered them in the other order, naive pairing matches plain-with-sauced and sauced-with-plain
 * and reports **two** modifier differences for an order that is completely correct.
 *
 * So pairing within a group of same-product lines is a minimum-cost assignment. The cost of pairing two
 * lines is the number of facets that differ — quantity, unit price, modifier set, instructions — and the
 * pairing chosen is the one with the lowest total. The example above costs 2 the naive way and 0 the
 * right way, so the right way wins on its own merits rather than by a special case.
 *
 * ## Exact at every size, by {@see hungarian()}
 *
 * This used to search exhaustively up to six lines of one product and fall back to a greedy pass above
 * that. Greedy is measurably worse — over randomised groups of seven to nine duplicate lines it reaches
 * totals up to five facet-differences above the optimum, which is five differences reported against a
 * trainee who did not make them. An assignment problem has a polynomial exact solution, so there is no
 * size at which this stops being optimal and no fallback to disagree with.
 *
 * ## Determinism, which matters as much as optimality
 *
 * Two runs over the same two orders must produce the same pairing, or the page changes under a reviewer
 * and a later scoring pass becomes unreproducible. So:
 *
 *  - the tie-break lives in the cost matrix, where equal-cost pairings resolve toward earlier demo
 *    lines rather than toward whichever one the algorithm reached first;
 *  - groups are processed in a sorted key order rather than in hash order.
 *
 * ## Identity, in two passes
 *
 * `product_id` is the merchant's catalogue id and both documents carry it, so lines are grouped by it
 * **when both sides have one**. Whatever is left is regrouped by canonicalised product name, which
 * catches a product whose id disagrees across the two systems but whose name does not. Only after both
 * passes is a line declared missing or extra.
 */
final readonly class ItemMatcher
{
    /**
     * Pairs, in a stable order: every original in document order, then every unpaired demo line.
     *
     * @param list<NormalizedItem> $originals
     * @param list<NormalizedItem> $demos
     *
     * @return list<array{original: ?NormalizedItem, demo: ?NormalizedItem}>
     */
    public function pair(array $originals, array $demos): array
    {
        /** @var array<int, int> $pairedDemoFor original index => demo index */
        $pairedDemoFor = [];
        $claimedDemos = [];

        // Pass 1: the shared catalogue id, used only where both sides actually have one.
        $this->pairWithin(
            $originals,
            $demos,
            static fn(NormalizedItem $item): ?string => $item->identityKey(),
            $pairedDemoFor,
            $claimedDemos,
        );

        // Pass 2: whatever is left, by name. Catches a product whose id the two systems disagree about.
        $this->pairWithin(
            $originals,
            $demos,
            static fn(NormalizedItem $item): ?string => $item->matchKey() === '' ? null : 'n' . $item->matchKey(),
            $pairedDemoFor,
            $claimedDemos,
        );

        $rows = [];

        foreach ($originals as $index => $original) {
            $demoIndex = $pairedDemoFor[$index] ?? null;

            $rows[] = [
                'original' => $original,
                'demo' => $demoIndex === null ? null : $demos[$demoIndex],
            ];
        }

        foreach ($demos as $index => $demo) {
            if (!isset($claimedDemos[$index])) {
                $rows[] = ['original' => null, 'demo' => $demo];
            }
        }

        return $rows;
    }

    /**
     * One grouping pass, mutating the pairing maps.
     *
     * @param list<NormalizedItem>              $originals
     * @param list<NormalizedItem>              $demos
     * @param callable(NormalizedItem): ?string $keyOf        null means "this pass cannot group it"
     * @param array<int, int>                   $pairedDemoFor
     * @param array<int, true>                  $claimedDemos
     */
    private function pairWithin(
        array $originals,
        array $demos,
        callable $keyOf,
        array &$pairedDemoFor,
        array &$claimedDemos,
    ): void {
        /** @var array<string, list<int>> $originalGroups */
        $originalGroups = [];
        /** @var array<string, list<int>> $demoGroups */
        $demoGroups = [];

        foreach ($originals as $index => $item) {
            if (isset($pairedDemoFor[$index])) {
                continue;
            }

            $key = $keyOf($item);

            if ($key !== null) {
                $originalGroups[$key][] = $index;
            }
        }

        foreach ($demos as $index => $item) {
            if (isset($claimedDemos[$index])) {
                continue;
            }

            $key = $keyOf($item);

            if ($key !== null) {
                $demoGroups[$key][] = $index;
            }
        }

        // Sorted, so the pairing cannot depend on insertion order.
        ksort($originalGroups);

        foreach ($originalGroups as $key => $originalIndexes) {
            $demoIndexes = $demoGroups[$key] ?? [];

            if ($demoIndexes === []) {
                continue;
            }

            foreach ($this->assign($originals, $demos, $originalIndexes, $demoIndexes) as [$o, $d]) {
                $pairedDemoFor[$o] = $d;
                $claimedDemos[$d] = true;
            }
        }
    }

    /**
     * The minimum-cost pairing of one group, as a list of `[originalIndex, demoIndex]`.
     *
     * Exact for every group this can be handed, via {@see hungarian()}. The branch-and-bound this
     * replaces was exact only to six lines of one product and fell back to a greedy pass above that,
     * and greedy is measurably worse: on randomised groups of seven to nine duplicate lines it reaches
     * a total five facet-differences worse than the optimum, which is five differences reported against
     * a trainee who did not make them.
     *
     * @param list<NormalizedItem> $originals
     * @param list<NormalizedItem> $demos
     * @param list<int>            $originalIndexes
     * @param list<int>            $demoIndexes
     *
     * @return list<array{int, int}>
     */
    private function assign(array $originals, array $demos, array $originalIndexes, array $demoIndexes): array
    {
        $n = count($originalIndexes);
        $m = count($demoIndexes);

        // The overwhelmingly common case: one line of this product on each side.
        if ($n === 1 && $m === 1) {
            return [[$originalIndexes[0], $demoIndexes[0]]];
        }

        // One scalar per pair, carrying both the cost and the tie-break — see `TIE_BREAK_BASE`.
        $weight = $n * $m + 1;
        $matrix = [];

        foreach ($originalIndexes as $row => $o) {
            foreach ($demoIndexes as $column => $d) {
                $matrix[$row][$column] = $this->cost($originals[$o], $demos[$d]) * $weight + $column;
            }
        }

        $pairs = [];

        foreach ($this->hungarian($matrix, $n, $m) as $row => $column) {
            $pairs[] = [$originalIndexes[$row], $demoIndexes[$column]];
        }

        return $pairs;
    }

    /**
     * Minimum-cost bipartite matching — the Hungarian method with potentials, O(n²m).
     *
     * ## Why an exact algorithm rather than a cap and a fallback
     *
     * Pairing duplicate lines is an assignment problem, and an assignment problem has a polynomial
     * exact solution. The previous shape — exhaustive search up to six lines, greedy above — was
     * exact where it was cheap and approximate exactly where a person would most want help. Greedy's
     * failure is not hypothetical: it takes the globally cheapest pair first, which can consume a line
     * two other cheap pairs needed.
     *
     * Runtime is cubic and the group is capped at {@see \App\OrderTesting\Infrastructure\DbOriginalItemsReader}'s
     * item ceiling, so the worst case is bounded by construction — no node budget, no recursion depth,
     * and no size at which the answer silently stops being optimal.
     *
     * ## Determinism
     *
     * The method itself is deterministic given a fixed matrix and iteration order. The tie-break is in
     * the matrix: each cost is scaled by `$weight` and the demo's position within the group added, and
     * because the largest possible sum of those positions is smaller than `$weight`, total cost is
     * always decided first and the sum of chosen demo positions only ever breaks a tie. So equal-cost
     * pairings resolve toward earlier demo lines, every time.
     *
     * Rows must not outnumber columns, so a wider-than-tall problem is solved transposed. Transposing
     * does not disturb the tie-break: it is baked into the values, not into the orientation.
     *
     * @param array<int, array<int, int>> $matrix rows = originals, columns = demos
     *
     * @return array<int, int> original row => demo column, for the rows that got one
     */
    private function hungarian(array $matrix, int $rows, int $columns): array
    {
        if ($rows > $columns) {
            $transposed = [];

            for ($i = 0; $i < $rows; ++$i) {
                for ($j = 0; $j < $columns; ++$j) {
                    $transposed[$j][$i] = $matrix[$i][$j];
                }
            }

            $flipped = [];

            foreach ($this->hungarian($transposed, $columns, $rows) as $column => $row) {
                $flipped[$row] = $column;
            }

            ksort($flipped);

            return $flipped;
        }

        $infinity = PHP_INT_MAX;

        // Potentials, and `matched[$j]` = the 1-based row currently holding column $j.
        $rowPotential = array_fill(0, $rows + 1, 0);
        $columnPotential = array_fill(0, $columns + 1, 0);
        $matched = array_fill(0, $columns + 1, 0);
        $previous = array_fill(0, $columns + 1, 0);

        for ($row = 1; $row <= $rows; ++$row) {
            // Column 0 is a sentinel standing for "the row being placed".
            $matched[0] = $row;
            $column = 0;
            $slack = array_fill(0, $columns + 1, $infinity);
            $visited = array_fill(0, $columns + 1, false);

            do {
                $visited[$column] = true;
                $currentRow = $matched[$column];
                $delta = $infinity;
                $next = 0;

                for ($candidate = 1; $candidate <= $columns; ++$candidate) {
                    if ($visited[$candidate]) {
                        continue;
                    }

                    $reduced = $matrix[$currentRow - 1][$candidate - 1]
                        - $rowPotential[$currentRow]
                        - $columnPotential[$candidate];

                    if ($reduced < $slack[$candidate]) {
                        $slack[$candidate] = $reduced;
                        $previous[$candidate] = $column;
                    }

                    if ($slack[$candidate] < $delta) {
                        $delta = $slack[$candidate];
                        $next = $candidate;
                    }
                }

                for ($candidate = 0; $candidate <= $columns; ++$candidate) {
                    if ($visited[$candidate]) {
                        $rowPotential[$matched[$candidate]] += $delta;
                        $columnPotential[$candidate] -= $delta;
                    } else {
                        $slack[$candidate] -= $delta;
                    }
                }

                $column = $next;
            } while ($matched[$column] !== 0);

            // Walk the augmenting path back, re-hanging each column on the row before it.
            do {
                $step = $previous[$column];
                $matched[$column] = $matched[$step];
                $column = $step;
            } while ($column !== 0);
        }

        $assignment = [];

        for ($column = 1; $column <= $columns; ++$column) {
            if ($matched[$column] > 0) {
                $assignment[$matched[$column] - 1] = $column - 1;
            }
        }

        ksort($assignment);

        return $assignment;
    }

    /**
     * How many facets differ: 0 for identical lines, 4 at most.
     *
     * Quantity and unit price go through the decimal rule, so `"1"` and `"1.000"` agree. Modifiers and
     * instructions go through the text rule, and the modifier signature is already order-insensitive.
     */
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
}
