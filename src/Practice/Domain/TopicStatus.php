<?php

declare(strict_types=1);

namespace App\Practice\Domain;

/**
 * What a topic is available for.
 *
 * Three states rather than a boolean, because "not offered for new work" and "deleted" are different
 * answers and a trainer needs both. `Inactive` is a pause: the topic is still in the list, still
 * assignable again tomorrow, and still readable on the orders that carry it.
 *
 * **`Archived` is the stored value behind Delete, and no screen says the word.** The storage keeps the
 * row so an assignment made a year ago still reads as what it was, and the generated unique key frees
 * the name for reuse (see the migration); the interface offers Delete, lists no archive and has no
 * filter for one. The value is spelled this way because that is what the table's CHECK constraint
 * allows, and changing it would be a migration that buys nothing.
 *
 * There is no fourth state and no hard delete. A topic is the label on somebody's training record.
 */
enum TopicStatus: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
    case Archived = 'ARCHIVED';

    public static function fromRequest(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::Archived => 'Archived',
        };
    }

    /** Only an active topic may be put on an order that does not already carry it. */
    public function acceptsNewAssignments(): bool
    {
        return $this === self::Active;
    }
}
