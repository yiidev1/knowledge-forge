<?php

declare(strict_types=1);

namespace App\AudioToText\Application;

use App\AudioToText\Domain\AudioConversationRepositoryInterface;
use App\AudioToText\Domain\RecordingType;
use App\AudioToText\Domain\Speaker\TranscriptVoice;
use App\AudioToText\Domain\TranscriptionJob;

/**
 * Whether this recording's speaker was already named, and what to call them.
 *
 * Every screen that renders a conversation asks this, and asks it the same way, so that a Caller
 * recording reads as one person's words on all of them at once. Written as one small reader rather
 * than as a lookup repeated in seven actions, because the day a fourth recording type appears there
 * has to be one place that learns about it.
 *
 * ## Why the lookup is by conversation
 *
 * The screens hold a **job** — a recording — and the declared type belongs to the **upload** above it.
 * One extra scalar read per page, on a primary key, which is the same shape as the store and public-id
 * lookups beside it. Carrying the type down onto every job row instead would mean a migration and a
 * second copy of a fact that already has a home.
 */
final readonly class RecordingVoiceReader
{
    public function __construct(private AudioConversationRepositoryInterface $conversations) {}

    /**
     * What this upload declared itself to be, or null where it declared nothing this reader honours.
     *
     * The primitive both answers are built from. Each caller maps it into its own vocabulary — the
     * screens into a {@see TranscriptVoice}, the generator into a
     * {@see \App\AudioToText\Domain\Tts\TtsVoice} — so the lookup and the legacy rule live here
     * once and neither mapping leaks into the other.
     */
    public function typeFor(TranscriptionJob $job): ?RecordingType
    {
        if ($job->conversationId === null) {
            return null;
        }

        // A legacy Customer + Agent half is never diarized and carries its identity in `source_role`,
        // so it keeps the behaviour it has always had. Only an upload that *declared* a type is making
        // the claim this reader exists to honour.
        if ($job->sourceRole?->isProvided() === true) {
            return null;
        }

        return $this->conversations->recordingTypeFor($job->conversationId);
    }

    /**
     * What to call this recording's speaker on screen, or null where it holds a conversation.
     *
     * Two columns are consulted, in order of how much they claim. A file whose `source_role` was
     * **declared** — every deterministic Customer or Agent channel the processing policy produces, and
     * every half of a legacy manual pair — already names whose words it holds, and that is the stronger
     * fact: it is a role, not a channel. Only when nothing was declared does the recording type answer,
     * which is the Caller / Callee case.
     *
     * Asking in that order is what keeps a deterministic channel from falling through to the publish
     * gate, which would find no inferred separation (correctly: none was attempted) and label every turn
     * "Unidentified speaker". It is also what withholds Confirm Roles and Move from those screens, since
     * every view already treats a named voice as "nothing left to establish".
     *
     * {@see typeFor()} is deliberately **not** changed by this: it answers a question about the channel,
     * and the AI-audio voice mapping is built on that answer.
     */
    public function for(TranscriptionJob $job): ?TranscriptVoice
    {
        return TranscriptVoice::forProvidedRole($job->sourceRole)
            ?? TranscriptVoice::forRecording($this->typeFor($job));
    }
}
