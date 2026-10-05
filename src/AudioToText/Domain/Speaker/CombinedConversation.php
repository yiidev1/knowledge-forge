<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Speaker;

use App\AudioToText\Domain\SpeakerRole;

use function array_values;

/**
 * A deterministic call, read from the two recordings that hold it.
 *
 * ## It owns no rows
 *
 * This is a projection and nothing else. The Customer recording is authoritative for the Customer's
 * words and the Agent recording for the Agent's; the mixed recording stores no transcript at all, and
 * nothing here is ever written anywhere. That is what makes a correction appear in both views at once
 * with no synchronisation job and nothing to drift: there is only one copy of each message, and both
 * screens read it.
 *
 * It is the reverse of {@see DerivedConversation}, which borrows the mixed recording's words for a
 * channel page because the mixed recording was the only place the two speakers had been told apart. A
 * deterministic call needs no diarization — each side arrives in its own file, already attributed — so
 * the direction turns around and the mixed recording becomes the borrower.
 *
 * ## Interleaved, or sectioned
 *
 * When both sides carry usable timestamps the messages are merge-sorted into one thread, overlaps and
 * all, with no time shifted. When they do not, {@see $interleaved} is false and the two sides are to be
 * rendered one after the other in their own orders — because an order nobody measured is not an order
 * worth asserting.
 */
final readonly class CombinedConversation
{
    /**
     * @param list<CombinedTurn> $turns in the order they are rendered
     */
    private function __construct(
        public CombinedConversationState $state,
        public array $turns,
        public ?CombinedChild $customer,
        public ?CombinedChild $agent,
        /** Whether {@see $turns} is one merged thread, or the two sides back to back. */
        public bool $interleaved,
    ) {}

    /**
     * @param list<CombinedTurn> $turns
     */
    public static function of(
        CombinedConversationState $state,
        array $turns,
        ?CombinedChild $customer,
        ?CombinedChild $agent,
        bool $interleaved = true,
    ): self {
        return new self($state, $turns, $customer, $agent, $interleaved);
    }

    /** A call whose sides could not be identified at all; no words, and a reason. */
    public static function unusable(CombinedConversationState $state): self
    {
        return new self($state, [], null, null, true);
    }

    public function isEmpty(): bool
    {
        return $this->turns === [];
    }

    /** Whether either side of the call was found, in any state. A caller's "is this projectable" test. */
    public function hasChildren(): bool
    {
        return $this->customer !== null || $this->agent !== null;
    }

    /**
     * Both sides, in a fixed order, skipping the absent one.
     *
     * @return list<CombinedChild>
     */
    public function children(): array
    {
        return array_values(array_filter([$this->customer, $this->agent]));
    }

    /** The side that owns this public id, or null when nothing in this projection does. */
    public function childOwning(string $jobPublicId): ?CombinedChild
    {
        foreach ($this->children() as $child) {
            if ($child->jobPublicId === $jobPublicId) {
                return $child;
            }
        }

        return null;
    }

    public function childFor(SpeakerRole $role): ?CombinedChild
    {
        return $role === SpeakerRole::AGENT ? $this->agent : $this->customer;
    }
}
