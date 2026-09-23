<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Tts;

use App\AudioToText\Domain\SpeakerRole;

/**
 * One thing that will be spoken, and who speaks it.
 *
 * The unit the digest is computed over and the unit sent to Deepgram, in that order and with nothing in
 * between — which is the invariant the whole "verbatim" claim rests on. If these two ever came from
 * different code paths, a file could be pinned to a hash of text that was never actually spoken.
 *
 * `$text` has already been through the one preparation pipeline: display markers stripped, invalid UTF-8
 * repaired, and empty turns dropped before they ever became an utterance. Nothing downstream is allowed
 * to touch it again. In particular the chunker splits it for transport and never alters a byte, and no
 * display formatter is applied — {@see \App\AudioToText\Application\SpokenPrice} is for reading, not for
 * speaking, and a price is on the list of things this feature must not rewrite.
 */
final readonly class TtsUtterance
{
    public function __construct(
        /**
         * Decides the voice, and nothing else.
         *
         * Only AGENT and CUSTOMER ever reach here: a script is built solely from turns whose role has
         * been published, because choosing a Customer voice is the same claim as printing the word
         * "Customer" — louder, since audio carries no way to mark it provisional.
         */
        public SpeakerRole $role,
        public string $text,
        /**
         * The voice that speaks this line, where the recording named one.
         *
         * Null for a conversation, whose voices come from the role above — that is the whole of the
         * mixed rendition's behaviour and none of it changes. Set for a recording that holds one side
         * of a call, where the role is the diarizer's guess about a cluster and must not choose a
         * sound. When it is set it is the only thing that does.
         */
        public ?TtsVoice $voice = null,
    ) {}

    public function isAgent(): bool
    {
        return $this->role === SpeakerRole::AGENT;
    }
}
