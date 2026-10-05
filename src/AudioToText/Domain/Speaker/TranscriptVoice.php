<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Speaker;

use App\AudioToText\Domain\RecordingType;
use App\AudioToText\Domain\SourceRole;

/**
 * A recording whose speaker is already known, because somebody said so when they uploaded it.
 *
 * ## The fact this represents
 *
 * A Caller recording holds one side of a call. Whatever the diarizer found inside it — and it will
 * find something, because it is always asked — every word in that file was spoken by the caller. The
 * clusters are real; what they are *not* is two people.
 *
 * So this is not a relabelling of the diarizer's output. It is a different and stronger fact, supplied
 * by a person at upload time, that makes the diarizer's speaker question inapplicable rather than
 * unanswered. `recording_type` is where that fact lives, and it outranks anything inferred from the
 * audio.
 *
 * ## What it is not
 *
 * **Not a role.** Caller and Callee say who placed the call; Agent and Customer say who works for the
 * restaurant. Nothing in this application has ever recorded which caller is which, and
 * {@see \App\AudioToText\Domain\Tts\TtsOutputType} still speaks the second vocabulary. A Caller
 * recording is not a Customer recording, and this class exists partly so that no code is tempted to
 * write that down.
 *
 * **Not for a legacy pair** when read through {@see forRecording()}. A SEPARATE Customer + Agent upload
 * carries its identity in `source_role` rather than in `recording_type`, so that factory answers null
 * for it. {@see forProvidedRole()} is the one that reads `source_role`, and it does name a role — see
 * its own note for why the two factories say different things.
 */
final readonly class TranscriptVoice
{
    private function __construct(
        /** What to call this speaker on screen — "Caller" or "Callee". */
        public string $label,
        /** Which side of the thread it sits on, so the two kinds of recording read differently. */
        public ConversationSide $side,
    ) {}

    /**
     * The voice a recording type names, or null where the recording holds a conversation.
     *
     * Mixed is null and so is a missing type: both mean "both sides are in here", which is the case
     * diarization exists for. Every row that predates recording types is in that second group, which
     * is why the absent case has to mean *conversation* rather than *unknown*.
     */
    public static function forRecording(?RecordingType $type): ?self
    {
        return match ($type) {
            RecordingType::Caller => new self(RecordingType::Caller->label(), ConversationSide::Left),
            RecordingType::Callee => new self(RecordingType::Callee->label(), ConversationSide::Right),
            RecordingType::Mixed, null => null,
        };
    }

    /**
     * The voice a file's declared `source_role` names, or null for a mixed recording.
     *
     * ## Why this says "Customer" where {@see forRecording()} says "Caller"
     *
     * They are answering different questions from different columns. `recording_type` records which
     * channel of a call a file is — who dialled — and `Caller` is the honest word for that. `source_role`
     * records who the file's words belong to, which is a role, and the only values it can hold are
     * exactly the two roles. So this is not a second opinion about the same fact; it is the fact the
     * other column never carried.
     *
     * ## What it is for
     *
     * A deterministic channel job — one the processing policy routed to a provided role — now stores
     * `speaker_segments` of its own, cut on the speaker's pauses. Without a voice, the conversation view
     * falls through to its publish gate, finds no published separation (there was none: nothing was
     * inferred), and labels every one of those turns "Unidentified speaker". A file whose speaker was
     * declared at import has no identification problem to report, so the fact is read here instead.
     */
    public static function forProvidedRole(?SourceRole $role): ?self
    {
        return match ($role) {
            SourceRole::Customer => new self(SourceRole::Customer->label(), ConversationSide::Left),
            SourceRole::Agent => new self(SourceRole::Agent->label(), ConversationSide::Right),
            SourceRole::Common, null => null,
        };
    }
}
