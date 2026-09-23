<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

use DateTimeImmutable;

use function array_map;
use function count;

/**
 * One row of a store's history: everything uploaded for one order.
 *
 * ## Why the row is an order rather than an upload
 *
 * A call recorded three ways is one call. Listing it as three rows made an administrator reconstruct
 * that fact by eye, every time, from filenames — and there was nothing else to go on, because the three
 * uploads share no visible identifier on screen. Grouping by order id says it once.
 *
 * ## An upload that named no order is still its own row
 *
 * Most of this database predates the order field. Those rows must not collapse into a single "no order"
 * heap, so each one is its own group keyed by its own conversation — see {@see GroupKey}. A group is
 * therefore "one order" or "one upload", never "everything that lacks an order".
 *
 * ## Slots
 *
 * Mixed, caller and callee are named fields rather than a list, because the table has a column for each
 * and a template asking "is there a caller recording" should not have to search. A legacy Customer +
 * Agent pair fills neither — it has no recording type at all — so it gets {@see $legacySeparate}, kept
 * whole rather than forced into a column it does not belong in.
 */
final readonly class StoreOrderGroup
{
    /**
     * @param list<StoreRecordingSlot> $legacySeparate both halves of a pre-recording-type pair
     */
    public function __construct(
        public GroupKey $key,
        /** The order this row is about, or null when the row is one upload that named none. */
        public ?string $orderId,
        public DateTimeImmutable $latestActivityAt,
        public ?StoreRecordingSlot $mixed = null,
        public ?StoreRecordingSlot $caller = null,
        public ?StoreRecordingSlot $callee = null,
        public array $legacySeparate = [],
    ) {}

    /**
     * Every recording in this group, primaries and history alike.
     *
     * @return list<StoreRecordingSlot>
     */
    public function allRecordings(): array
    {
        $slots = [];

        foreach ($this->primaries() as $primary) {
            // `withHistory()`, not the slot alone: an order whose caller side was uploaded twice has
            // two caller recordings, and a count or a status that quietly spoke for only the newest
            // would be describing a smaller call than the one that happened.
            foreach ($primary->withHistory() as $slot) {
                $slots[] = $slot;
            }
        }

        return $slots;
    }

    /**
     * The primary recording of each kind — what the table's cells and the group modals speak about.
     *
     * @return list<StoreRecordingSlot>
     */
    public function primaries(): array
    {
        $slots = [];

        foreach ([$this->mixed, $this->caller, $this->callee] as $slot) {
            if ($slot !== null) {
                $slots[] = $slot;
            }
        }

        foreach ($this->legacySeparate as $slot) {
            $slots[] = $slot;
        }

        return $slots;
    }

    /**
     * One state for the whole row.
     *
     * Every job in the group is fed to the rule this application already uses to turn several child
     * states into one, so a row can say "Partially completed" for the same reason a paired upload does
     * and the two screens cannot disagree. Nothing here is a new status, and nothing is taken from
     * whichever recording happens to be newest.
     */
    public function aggregateStatus(): ConversationStatus
    {
        return ConversationStatus::fromChildren(array_map(
            static fn(StoreRecordingSlot $slot): JobStatus => $slot->status,
            $this->allRecordings(),
        ));
    }

    public function recordingCount(): int
    {
        return count($this->allRecordings());
    }

    public function isLegacySeparate(): bool
    {
        return $this->legacySeparate !== [];
    }

    /** Whether anything in this group has a transcript worth opening. */
    public function hasAnyTranscript(): bool
    {
        foreach ($this->primaries() as $slot) {
            if ($slot->hasReadableTranscript()) {
                return true;
            }
        }

        return false;
    }
}
