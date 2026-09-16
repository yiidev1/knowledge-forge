<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Transcription;

use App\AudioToText\Domain\Speaker\TranscriptToken;

/**
 * What an engine produced, in terms the rest of the pipeline already understands.
 *
 * Provider-neutral by construction: three fields, none of which names a vendor, a file format or a
 * response shape. Whisper fills them from `-otxt` and `-ojf`; Deepgram fills them from one JSON body.
 * Everything downstream — alignment, diarization, role mapping, the review layer — sees the same
 * object either way and cannot tell which engine ran.
 *
 * Lives in Domain rather than Infrastructure because both engines *produce* it and the application
 * layer *consumes* it; it belongs to neither implementation.
 */
final readonly class AudioTranscriptionResult
{
    /**
     * @param list<TranscriptToken> $tokens timed tokens, chronological. Empty is legal — speaker
     *                                      separation then reports a failure and the transcript is
     *                                      still stored, which is the degradation this feature is
     *                                      built around.
     */
    public function __construct(
        public string $text,
        public ?string $language,
        public array $tokens,
    ) {}
}
