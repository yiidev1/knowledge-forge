<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Speaker;

use App\AudioToText\Domain\RecordingType;

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
 * **Not for a legacy pair.** A SEPARATE Customer + Agent upload already carries its identity in
 * `source_role`, is never diarized, and keeps the presentation it has always had.
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
}
