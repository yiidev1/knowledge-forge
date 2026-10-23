<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain\Practice;

use App\OrderTesting\Domain\InitiatorType;

use function count;

/**
 * Everything one person did on one source order — or the demo orders nobody can be shown to have done.
 *
 * ## Why the unmatched bucket is a group and not a footnote
 *
 * A demo order whose creating attempt cannot be identified is not "somebody's try we have not labelled
 * yet". It is a record with no owner, and the page says exactly that. Giving it a group of its own —
 * with `actorType` null — is what stops it being rendered under the heading of whoever happens to be
 * listed nearby.
 */
final readonly class AttemptGroup
{
    /**
     * @param list<PracticeTry> $tries oldest first, numbered from 1 within this group alone
     */
    public function __construct(
        public ?InitiatorType $actorType,
        public ?int $actorId,
        public ?string $actorName,
        public array $tries,
    ) {}

    public function isUnmatched(): bool
    {
        return $this->actorType === null;
    }

    /**
     * What to call this person on screen.
     *
     * The stored name when the account still exists, the realm and id when it does not — an attempt
     * outlives the account that made it, and "ADMIN #12" is a true statement where a blank is not.
     */
    public function label(): string
    {
        if ($this->actorType === null) {
            return 'Unmatched';
        }

        if ($this->actorName !== null && $this->actorName !== '') {
            return $this->actorName;
        }

        return $this->actorType->label() . ' #' . (string) $this->actorId;
    }

    /** A stable key for filtering, independent of the display name. */
    public function key(): string
    {
        return $this->actorType === null
            ? 'unmatched'
            : $this->actorType->value . ':' . (string) $this->actorId;
    }

    public function tryCount(): int
    {
        return count($this->tries);
    }

    /** The highest score anybody reached in this group, for the summary line. */
    public function bestPercent(): ?int
    {
        $best = null;

        foreach ($this->tries as $try) {
            $outcome = $try->best();

            if ($outcome === null || $outcome->score->overall === null) {
                continue;
            }

            $percent = $outcome->score->displayPercent();

            if ($percent !== null && ($best === null || $percent > $best)) {
                $best = $percent;
            }
        }

        return $best;
    }
}
