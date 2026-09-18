<?php

declare(strict_types=1);

namespace App\AudioToText\Application\Tts;

use App\AudioToText\Application\TranscriptText;

use function preg_replace;
use function trim;

/**
 * The one preparation a transcript gets before it is hashed and spoken — and the complete list of it.
 *
 * ## Why this exists rather than reusing the display stripper
 *
 * {@see \App\AudioToText\Domain\Speaker\SpeakerMarkers::strip()} does the same marker removal and then
 * also collapses every run of whitespace, because that reads better in a bubble. That is the right call
 * for a bubble and the wrong one to pin a paid artifact to: it is a **display convenience**, its own
 * docblock says so, and the day somebody improves it for the screen, every generated file in the
 * database would silently become "stale" and invite an administrator to buy it again.
 *
 * So the rule for spoken text is written down here, separately, and changing it is an explicit decision
 * about audio rather than a side effect of a decision about layout.
 *
 * ## What is done, and why each one is unavoidable
 *
 * 1. **`>>` speaker-change markers are removed.** They are real information that stays in `transcript`
 *    and `speaker_segments` for the correction workflow, but they are not words. Left in, Deepgram reads
 *    them — a training recording that says "right, chevron chevron, she wants" is worse than useless.
 *    The whitespace immediately around a removed marker collapses to one space, because the marker took
 *    up room and the gap it leaves behind is an artefact of deleting it, not something anyone wrote.
 * 2. **Invalid UTF-8 is repaired**, through the same {@see TranscriptText::toValidUtf8()} every other
 *    consumer uses. A malformed byte would be rejected by the provider's JSON encoder anyway; repairing
 *    it here means the digest is computed over exactly what is sent, rather than over something that
 *    could not be.
 * 3. **Leading and trailing whitespace is trimmed.** Inaudible, and it keeps a turn that is nothing but
 *    spaces from becoming a paid request that produces silence.
 *
 * ## What is deliberately *not* done
 *
 * Interior whitespace is left exactly as it is. Punctuation is not normalised, capitalisation is not
 * changed, and no number, price, quantity, product name, address or payment detail is rewritten.
 * {@see \App\AudioToText\Application\SpokenPrice} in particular is **not** applied: it is a reading aid
 * for uncorrected machine text, and a price is precisely the kind of thing this feature must not decide
 * it knows better about. No language model is involved at any point.
 */
final class TtsSourceText
{
    /**
     * Prepare one turn's text for hashing and speaking.
     *
     * Returns an empty string for a turn that has nothing left to say, which the script builder uses to
     * drop it before it can become either a hash input or a billed request.
     */
    public static function prepare(string $text): string
    {
        $valid = TranscriptText::toValidUtf8($text);

        // The marker and the spacing it sat in become a single space: removing `>>` from
        // "right? >> She wants" must not leave "right?  She wants" with a hole where it used to be.
        $withoutMarkers = preg_replace('/\s*>>\s*/u', ' ', $valid) ?? $valid;

        return trim($withoutMarkers);
    }
}
