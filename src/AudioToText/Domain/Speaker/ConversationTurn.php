<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Speaker;

/**
 * One line of the conversation as it is shown to an administrator.
 *
 * Deliberately a *display* value rather than the stored {@see SpeakerUtterance}: the label depends on
 * how much the system is entitled to claim, which is a property of the separation result as a whole and
 * not of any single utterance. Building it here keeps that decision out of the template.
 */
final readonly class ConversationTurn
{
    /**
     * @param string $label     what to show in the speaker column — either a confirmed role or a
     *                          neutral speaker name
     * @param bool   $confirmed whether `$label` is a role the system is prepared to stand behind. False
     *                          for every neutral label, and the flag the tests assert on.
     */
    public function __construct(
        public string $label,
        public string $text,
        public bool $confirmed,
    ) {}
}
