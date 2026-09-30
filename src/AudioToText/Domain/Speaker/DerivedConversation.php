<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Speaker;

use App\AudioToText\Domain\SpeakerRole;

/**
 * One side of a call, read from the mixed recording that holds the whole of it.
 *
 * ## What this is, and what it is not
 *
 * A Caller recording and a Callee recording are separate files with separate transcriptions. Their
 * words, their turn boundaries and their timings all differ from the mixed recording's, because they
 * are three independent transcriptions of three different audio files — on the one real example in this
 * database the mixed conversation has ten turns, the caller file three and the callee file two, and a
 * single callee turn spans four mixed agent turns and three mixed customer turns. Nothing can be
 * mapped between them by timestamp, and nothing here tries.
 *
 * So this is **not a synchronised copy**. It is the mixed conversation itself, filtered to one role, and
 * borrowed for display on the channel recording's page. Nothing is written anywhere: no text is copied
 * into the channel job's `reviewed_segments`, its own corrections and revision history are neither read
 * nor touched, and a correction made on the mixed conversation is visible here on the very next read
 * because there is only ever one copy of it.
 *
 * That also settles reverting: discarding the mixed conversation's corrections restores this view in the
 * same instant, with no second revert to remember and nothing left behind to drift.
 *
 * ## Why it is read-only
 *
 * The editor on a channel recording's page edits **that recording's** turns. Showing borrowed text above
 * controls that would write somewhere else is the one arrangement guaranteed to lose an edit, so where
 * these turns are displayed the per-turn controls are withheld and the page says where the words come
 * from. The recording's own transcript and its own history stay reachable, unchanged.
 */
final readonly class DerivedConversation
{
    /**
     * @param list<SpeakerUtterance> $utterances the mixed conversation's turns for this role, in order
     */
    public function __construct(
        public array $utterances,
        /** Whose words these are, as confirmed on the mixed conversation. */
        public SpeakerRole $role,
        /** The mixed conversation's public id, so a page can link a reader to where edits are made. */
        public string $sourcePublicId,
    ) {}

    public function isEmpty(): bool
    {
        return $this->utterances === [];
    }
}
