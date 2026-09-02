<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Speaker;

use function preg_replace;
use function trim;

/**
 * Strips whisper's `>>` speaker-change markers from text on its way to a reader.
 *
 * The transcriber emits `>>` where it hears the speaker change mid-utterance. That is genuine
 * information — it is often the only clue that a diarized turn contains two people — so it stays in
 * `transcript` and in `speaker_segments`, where the correction workflow can still act on it.
 *
 * It is noise to read, though. A conversation already shows who is speaking above every bubble, so a
 * marker inside the text says nothing the layout has not already said, and it makes the sentence
 * harder to follow.
 *
 * **Display only.** Nothing here writes anywhere. The stored text keeps its markers; the exception is
 * a turn an administrator rewords by hand, where what they see is what they save — which is the point
 * of an editor.
 */
final class SpeakerMarkers
{
    public static function strip(string $text): string
    {
        // The marker plus whatever spacing surrounded it becomes one space, so "right? >> She wants"
        // reads as one sentence rather than gaining a double gap where the marker used to be.
        $withoutMarkers = preg_replace('/\s*>>\s*/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $withoutMarkers) ?? $withoutMarkers);
    }
}
