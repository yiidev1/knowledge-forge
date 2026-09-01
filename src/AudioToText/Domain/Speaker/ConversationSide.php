<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Speaker;

/**
 * Which side of the thread a turn is drawn on.
 *
 * Position is a reading aid, not a claim. When roles are published the sides carry meaning — customer
 * left, agent right, the arrangement everyone already knows from a messaging app. When they are *not*
 * published the sides still alternate by neutral cluster, so the exchange remains legible as a
 * back-and-forth, but the label above each bubble stays "Speaker 1" / "Speaker 2". A reader can follow
 * the conversation without being told something the system does not know.
 *
 * Unattributed speech sits in the middle, belonging to neither column, because putting it on a side
 * would imply it was spoken by whoever else is over there.
 */
enum ConversationSide: string
{
    case Left = 'left';
    case Right = 'right';
    case Neutral = 'neutral';

    /** The CSS modifier for this side — kept beside the enum so the template picks no class names. */
    public function modifier(): string
    {
        return 'a2t-turn--' . $this->value;
    }
}
