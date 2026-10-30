<?php

declare(strict_types=1);

namespace App\OrderTesting\Web;

use App\OrderTesting\Domain\Comparison\ComparisonStatus;

/**
 * What a verdict is called on the comparison page and the receipt page — one vocabulary for both.
 *
 * A product line and a field are worded differently on purpose, because the same status means
 * different things for them:
 *
 *  - a **product** only the demo has is a product the trainee added, and it is charged — so it reads
 *    "Extra product in demo";
 *  - a **field value** only the demo has (a state, a name) is overwhelmingly a gap in the original
 *    record, and is not charged — so it reads "Missing in original", which says exactly that.
 *
 * Something the original has and the demo does not reads "Missing from demo" for both.
 */
final class VerdictWords
{
    public static function field(ComparisonStatus $status): string
    {
        return match ($status) {
            ComparisonStatus::Match => 'Match',
            ComparisonStatus::Different => 'Different',
            ComparisonStatus::MissingInDemo => 'Missing from demo',
            ComparisonStatus::ExtraInDemo => 'Missing in original',
            ComparisonStatus::Uncomparable => 'Not available',
        };
    }

    /** A product line's verdict. "Different" means a scored detail of a matched product differs. */
    public static function item(ComparisonStatus $status): string
    {
        return $status === ComparisonStatus::ExtraInDemo ? 'Extra product in demo' : self::field($status);
    }
}
