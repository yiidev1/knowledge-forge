<?php

declare(strict_types=1);

namespace App\AudioToText\Application;

use App\AudioToText\Domain\AudioConversationRepositoryInterface;
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

    public function for(TranscriptionJob $job): ?TranscriptVoice
    {
        if ($job->conversationId === null) {
            return null;
        }

        // A legacy Customer + Agent half is never diarized and carries its identity in `source_role`,
        // so it keeps the presentation it has always had. Only an upload that *declared* a type is
        // making the claim this reader exists to honour.
        if ($job->sourceRole?->isProvided() === true) {
            return null;
        }

        return TranscriptVoice::forRecording($this->conversations->recordingTypeFor($job->conversationId));
    }
}
