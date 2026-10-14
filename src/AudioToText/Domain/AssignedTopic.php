<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

/**
 * A topic as a practice-orders row shows it.
 *
 * Carries the name rather than only the id so the table renders without a second lookup, the
 * description so the row's details can be read without a request, and the stored status so the row can
 * say precisely *why* a topic is no longer offered instead of only that it is not.
 *
 * `assignable` is derived from the status rather than passed beside it, so the two can never disagree —
 * the bug that shape invites is a row claiming "no longer offered" about a topic the picker is still
 * offering.
 */
final readonly class AssignedTopic
{
    /** The stored values. Named here because this module reads the table by name and never its enum. */
    public const ACTIVE = 'ACTIVE';
    public const INACTIVE = 'INACTIVE';
    public const DELETED = 'ARCHIVED';

    public function __construct(
        public int $id,
        public string $name,
        public string $status = self::ACTIVE,
        public ?string $description = null,
    ) {}

    /** Only an active topic may be put on an order that does not already carry it. */
    public function isAssignable(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /** What the status is called on screen. */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::ACTIVE => 'Active',
            self::INACTIVE => 'Inactive',
            self::DELETED => 'Deleted',
            default => $this->status,
        };
    }

    /**
     * Why this topic cannot be chosen again, or '' when it can.
     *
     * Said precisely: "no longer offered" covers two different situations, and an administrator
     * deciding what to do about one of them needs to know which it is.
     */
    public function unavailableNote(): string
    {
        return match ($this->status) {
            self::INACTIVE => 'Inactive — kept on this order, not offered for new ones',
            self::DELETED => 'Deleted — kept on this order, not offered for new ones',
            default => '',
        };
    }
}
