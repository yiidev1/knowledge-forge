<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain\Comparison;

/**
 * What one comparison found.
 *
 * Deliberately a fact, never a judgement. Nothing here says a difference is *wrong* or how much it is
 * worth — that is scoring, which is explicitly not part of this phase. A later scoring pass reads these
 * statuses; it does not have to re-derive them, and it does not have to scrape a page to get them.
 *
 * `Uncomparable` is its own case rather than being folded into `Different`, because "the source order is
 * not in the local mirror" and "the trainee typed the wrong total" are different facts and a score that
 * conflated them would punish a trainee for a synchronisation gap.
 */
enum ComparisonStatus: string
{
    /** Both sides present and equal under the stated rule. */
    case Match = 'MATCH';

    /** Both sides present and not equal. */
    case Different = 'DIFFERENT';

    /** The original has it; the demo does not. */
    case MissingInDemo = 'MISSING_IN_DEMO';

    /** The demo has it; the original does not. */
    case ExtraInDemo = 'EXTRA_IN_DEMO';

    /** No comparison is possible — typically because the source order is not mirrored locally. */
    case Uncomparable = 'UNCOMPARABLE';

    public function label(): string
    {
        return match ($this) {
            self::Match => 'Match',
            self::Different => 'Different',
            self::MissingInDemo => 'Missing in demo',
            self::ExtraInDemo => 'Extra in demo',
            self::Uncomparable => 'Not comparable',
        };
    }

    /** CSS modifier suffix, so the template carries no colour decisions of its own. */
    public function modifier(): string
    {
        return match ($this) {
            self::Match => 'match',
            self::Different => 'different',
            self::MissingInDemo => 'missing',
            self::ExtraInDemo => 'extra',
            self::Uncomparable => 'unknown',
        };
    }
}
