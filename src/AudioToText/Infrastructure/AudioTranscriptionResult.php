<?php

declare(strict_types=1);

namespace App\AudioToText\Infrastructure;

use App\AudioToText\Domain\Speaker\TranscriptToken;

/**
 * What one whisper.cpp run produced.
 *
 * The tokens come from the same invocation as the text: `-otxt`, `-oj` and `-ojf` are all passed in one
 * pass, so timestamped tokens cost no additional CPU. Transcribing twice — once for the readable
 * transcript and once for timings — would have doubled a ninety-four-second job for nothing.
 */
final readonly class AudioTranscriptionResult
{
    /**
     * @param list<TranscriptToken> $tokens empty when whisper emitted no token-level timings, in which
     *                                      case speaker separation reports a failure and the transcript
     *                                      is still perfectly usable
     */
    public function __construct(
        public string $text,
        public ?string $language,
        public array $tokens,
    ) {}
}
