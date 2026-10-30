<?php

declare(strict_types=1);

namespace App\OrderTesting\Application\Comparison;

use App\OrderTesting\Domain\Comparison\ComparisonSection;
use App\OrderTesting\Domain\Comparison\ComparisonStatus;
use App\OrderTesting\Domain\Comparison\FieldComparison;
use App\OrderTesting\Domain\Comparison\ItemComparison;
use App\OrderTesting\Domain\Comparison\OrderComparison;

use function array_fill_keys;
use function trim;

/**
 * What the receipt comparison page draws, and the counts that describe it.
 *
 * ## Why this exists rather than more code in the template
 *
 * The page shows the same facts twice: once as rows inside two receipts, and once as a line of counts
 * above them. If the template decided which rows to draw and a separate tally decided what to count,
 * the two would disagree the first time either changed — and a summary that disagrees with the document
 * under it is worse than no summary. So the decision is taken once, here, and the counts are derived
 * from the very list the page iterates.
 *
 * Nothing in this class formats anything. It holds comparison results; how an amount is written is the
 * template's business, which is what keeps a display rounding from ever changing a verdict.
 *
 * ## What is counted
 *
 * Every field row the page draws with a verdict, and every line item. A field both documents left empty
 * is neither drawn nor counted: two orders that both say nothing have not agreed about anything, and
 * counting that as a match would inflate the score with silence. `Uncomparable` is counted on its own,
 * because "we cannot tell" is not "the same".
 *
 * The receipt follows the comparison page's rules: Customer count and Status are not drawn
 * (`ComparisonScorer::HIDDEN_FIELDS`); the Summary is drawn without verdicts, so its rows are not
 * counted; and a line item is counted under the verdict the score gave it, so a line that differs only
 * in its unit price counts as matched.
 */
final readonly class ReceiptComparison
{
    /**
     * @param list<array{title: string, fields: list<FieldComparison>}> $sections
     * @param array<string, int>                                       $tally keyed by status value
     */
    private function __construct(
        public OrderComparison $comparison,
        public array $sections,
        public array $tally,
    ) {}

    public static function from(OrderComparison $comparison): self
    {
        $sections = [];
        $tally = array_fill_keys(
            [
                ComparisonStatus::Match->value,
                ComparisonStatus::Different->value,
                ComparisonStatus::MissingInDemo->value,
                ComparisonStatus::ExtraInDemo->value,
                ComparisonStatus::Uncomparable->value,
            ],
            0,
        );

        foreach ($comparison->sections as $section) {
            /** @var ComparisonSection $section */
            $fields = [];

            foreach (ComparisonScorer::shownFields($section) as $field) {
                if (self::isBlank($field->original) && self::isBlank($field->demo)) {
                    continue;
                }

                $fields[] = $field;

                // The Summary is for reference: drawn without a verdict, so not counted as one.
                if ($section->title !== ComparisonScorer::TOTALS) {
                    ++$tally[$field->status->value];
                }
            }

            if ($fields === []) {
                continue;
            }

            $sections[] = ['title' => $section->title, 'fields' => $fields];
        }

        foreach ($comparison->items as $item) {
            /** @var ItemComparison $item */
            ++$tally[ComparisonScorer::itemVerdict($item)->value];
        }

        return new self($comparison, $sections, $tally);
    }

    /** How many rows carry this verdict. */
    public function count(ComparisonStatus $status): int
    {
        return $this->tally[$status->value] ?? 0;
    }

    /** Every row the page draws, whatever its verdict. */
    public function total(): int
    {
        $sum = 0;

        foreach ($this->tally as $count) {
            $sum += $count;
        }

        return $sum;
    }

    /** Said nothing at all. Not the same as said zero: `'0'` and `'0.00'` are answers. */
    private static function isBlank(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }
}
