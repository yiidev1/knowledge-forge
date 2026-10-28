<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain\Comparison;

/**
 * How closely one demo order reproduced one original order.
 *
 * ## What this is, and what it is not
 *
 * It is a measurement of agreement between two documents — this demo order against the original it was
 * entered from. It is **not** an average over the several demo orders one call produced, not a figure
 * for a store or a period, and not a judgement about the person who typed it. The page says so beside
 * the number, because a percentage with no stated scope is the kind of thing that ends up in a review.
 *
 * ## Unavailable is an answer
 *
 * `overall === null` means the comparison could not support a score — the original order is not
 * mirrored, or its item list has not been fetched. Half the weight lives in the items, so a number
 * built from the other half would be a different measurement wearing this one's name. The reason is
 * carried so the page can say which it was.
 */
final readonly class ComparisonScore
{
    /**
     * @param list<SectionScore>   $sections  every section, scored or not, in the page's own order
     * @param array<string, float> $weighting the whole weighting, summing to 100, for the page to
     *                                        state without holding a second copy of it
     */
    public function __construct(
        public ?float $overall,
        public ?string $unavailableReason,
        public array $sections,
        public array $weighting = [],
    ) {}

    public function isAvailable(): bool
    {
        return $this->overall !== null;
    }

    /**
     * Whether the demo order matched the original completely — the numeric score, not its printed form.
     *
     * The same boundary {@see SectionScore::display()} prints 100 at: anything short of 100.0, however
     * close, is not a perfect score and does not display as one.
     */
    public function isPerfect(): bool
    {
        return $this->overall !== null && $this->overall >= 100.0;
    }

    /** Rounded for display by the same rule the sections use — see {@see SectionScore::display()}. */
    public function displayPercent(): ?int
    {
        return $this->overall === null ? null : SectionScore::display($this->overall);
    }

    public function section(string $title): ?SectionScore
    {
        foreach ($this->sections as $section) {
            if ($section->title === $title) {
                return $section;
            }
        }

        return null;
    }
}
