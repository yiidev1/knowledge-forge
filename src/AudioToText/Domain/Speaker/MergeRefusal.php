<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Speaker;

/**
 * Why a merge would be refused — or that it would not be.
 *
 * This exists so the page can explain a disabled control without re-deriving the rule. The rule itself
 * is unchanged and still lives in one place: {@see ReviewedConversationTurns::merge()} asks
 * {@see ReviewedConversationTurns::mergeAvailability()} and throws on anything but {@see self::None},
 * so the reason shown to an administrator and the reason the service enforces cannot drift apart.
 *
 * `DifferentSpeaker` is the case the wording matters most for. Once two adjacent turns have been moved
 * to the same role they look identical on screen — the diarization identity that separates them is
 * never printed — so a control that simply vanished would read as a bug rather than as a safeguard.
 */
enum MergeRefusal
{
    /** The merge is allowed. */
    case None;

    /** First turn asked to merge upward, or last turn asked to merge downward. */
    case NoNeighbour;

    /** The neighbour is assigned to the other role, which is visible on screen. */
    case DifferentRole;

    /** The neighbour was recorded as a different voice, which is not visible on screen. */
    case DifferentSpeaker;

    public function isAllowed(): bool
    {
        return $this === self::None;
    }

    /**
     * Wording for the administrator, or null when there is nothing to explain.
     *
     * Each says what the system observed rather than what it refuses to do, and where there is a way
     * forward it names it.
     */
    public function reason(): ?string
    {
        return match ($this) {
            self::None => null,
            self::NoNeighbour => 'There is no turn on that side to merge with.',
            self::DifferentRole => 'That turn is assigned to the other role. Move one of them first.',
            self::DifferentSpeaker => 'The system heard two different voices here, so these turns cannot be '
                . 'joined. Their text is still kept separately for each role.',
        };
    }
}
