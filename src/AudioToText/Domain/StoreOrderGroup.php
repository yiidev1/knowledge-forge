<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

use App\Shared\Audio\RecordingAcquisition;
use App\Shared\Audio\RecordingAcquisitionState;
use DateTimeImmutable;

use function array_filter;
use function array_map;
use function array_values;
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
        /**
         * When the call happened, as the provider wrote it, or null when nothing recorded that.
         *
         * A different fact from `$latestActivityAt`, which is when this application imported the
         * recording — often hours later. The two were being shown as one, and the page now says which
         * is which. A string, because the provider does not document its timezone.
         *
         * **Last in the list and defaulted**, so every existing construction site builds exactly the
         * group it built before. Adding it mid-signature would silently shift `$mixed` along by one.
         */
        public ?string $callTimeRaw = null,

        /**
         * Recordings of this call still on their way in, if any.
         *
         * Null for every row this page has ever shown: a group is built from stored conversations, and
         * a conversation exists only once its audio has landed. This is the other half — set from the
         * shared seam for a call somebody has asked for and whose channels have not all arrived.
         *
         * Carrying it on the group rather than in a parallel map means a slot cell can ask one object
         * both questions — "is there a recording here?" and "is one coming?" — and the template never
         * has to decide which of two sources a row came from.
         *
         * **Last in the list and defaulted**, so every existing construction site builds exactly the
         * group it built before.
         */
        public ?RecordingAcquisition $arriving = null,
    ) {}

    /**
     * Whether anything about this row is still expected to change on its own.
     *
     * Two different kinds of waiting, and the page polls for either: audio still being downloaded, or
     * a transcript being produced for audio that is already here.
     */
    public function isSettling(): bool
    {
        if ($this->arriving !== null && $this->arriving->isActive()) {
            return true;
        }

        foreach ($this->primaries() as $slot) {
            if ($slot->status->isActive()) {
                return true;
            }
        }

        return false;
    }

    /**
     * What is coming for one channel, or null when nothing is.
     *
     * Returns null for a channel that has already arrived as well as for one never asked for: once
     * there is a recording the cell draws a player, and a status beside it would be describing work
     * that is finished.
     */
    public function arrivingFor(string $channel): ?RecordingAcquisitionState
    {
        $state = $this->arriving?->channels[$channel] ?? null;

        return $state === null || $state->isAvailable() ? null : $state;
    }

    /** A row with no recordings at all yet — everything it has is still being fetched. */
    public function isArrivingOnly(): bool
    {
        return $this->arriving !== null && $this->primaries() === [];
    }

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
     * Whether anything in this row can be opened for reading and correction.
     *
     * Asked by the template to decide whether the order id is a way in or plain text. A row whose only
     * recording is still being transcribed has nothing a details view could show — that endpoint
     * answers 404 for a recording with nothing to correct — so the id stays inert and Manage Audio
     * remains the place to watch it finish.
     *
     * Over the primaries alone, which is what the row shows. A superseded version is reachable from
     * Manage Audio and is not what the order id opens.
     */
    public function hasReviewableRecording(): bool
    {
        foreach ($this->primaries() as $slot) {
            if ($slot->isReviewable()) {
                return true;
            }
        }

        return false;
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
     * One state for the whole row, describing the call as it currently stands.
     *
     * Each **current** recording is fed to the rule this application already uses to turn several child
     * states into one, so a row can say "Partially completed" for the same reason a paired upload does
     * and the two screens cannot disagree. Nothing here is a new status.
     *
     * ## Why superseded versions are not counted
     *
     * They are answers to a question nobody is asking any more. Once a recording can be replaced, the
     * history holds two kinds of row a status must not speak for: a replacement still being transcribed,
     * and one that failed. Counting either would report an order as unfinished or broken at the very
     * moment its current recordings are all complete and correct — which is the opposite of what this
     * column exists to say, and would make a failed replacement look like damage to the call itself.
     *
     * What happened to a replacement is the Manage Audio dialog's business, where the version it
     * belongs to is named. See {@see allRecordings()} for the count that does include history.
     */
    public function aggregateStatus(): ConversationStatus
    {
        $primaries = $this->primaries();

        // An audio-only recording is not work this column is waiting on.
        //
        // {@see ConversationStatus::fromChildren()} counts NOT_REQUESTED as neither terminal nor
        // running, so a child in that state leaves the group short of "every child finished" and the
        // method falls through to PROCESSING. For a deterministic call that reads **"Transcribing"
        // forever**: the mixed recording is NOT_REQUESTED by design and never leaves it, so a call whose
        // Customer and Agent are both complete never reports as finished.
        //
        // {@see StoreRecordingSlot::isAudioOnly()} is the existing answer to "is this recording kept for
        // its audio, with no transcript of its own and none coming" — status plus declared role, the
        // same pair the policy uses. Only that combination is set aside, so a mixed recording that was
        // genuinely transcribed still counts, and a Customer or Agent side sitting at NOT_REQUESTED is
        // still outstanding work somebody can ask for ({@see StoreRecordingSlot::isReadyForTranscription()}).
        $deciding = array_values(array_filter(
            $primaries,
            static fn(StoreRecordingSlot $slot): bool => !$slot->isAudioOnly(),
        ));

        // An order that is *only* audio-only recordings has nothing else to go on, and its own state is
        // the honest answer. Falling back to the full list keeps a mixed-only upload reporting exactly
        // what it reported before this existed.
        return ConversationStatus::fromChildren(array_map(
            static fn(StoreRecordingSlot $slot): JobStatus => $slot->status,
            $deciding === [] ? $primaries : $deciding,
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
