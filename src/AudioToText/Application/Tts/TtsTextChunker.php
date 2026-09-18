<?php

declare(strict_types=1);

namespace App\AudioToText\Application\Tts;

use function mb_strlen;
use function mb_substr;
use function preg_match_all;
use function strlen;
use function substr;

use const PREG_OFFSET_CAPTURE;

/**
 * Splits one utterance into pieces small enough to send, and changes nothing else about it.
 *
 * ## The contract
 *
 * `implode('', split($text, $max)) === $text`, byte for byte, for every input. Not "modulo whitespace",
 * not "after normalisation" — exactly. A chunk boundary is a **transport** boundary: it exists because
 * Deepgram's Aura models answer HTTP 413 above 2,000 characters, and for no other reason.
 *
 * That contract is why this class adds no space, trims no space, normalises no punctuation, changes no
 * capitalisation and never drops a character it cannot place. The audio has to be a reading of the
 * transcript an administrator corrected; a chunker that tidied as it went would make the two disagree in
 * ways nobody would notice until a price or a product name came out wrong.
 *
 * The digest is computed over the whole utterance *before* this runs, so chunking can never move a hash
 * either. {@see TtsSourceDigest}.
 *
 * ## Where it cuts, and why in that order
 *
 * 1. **After a sentence.** A terminator, any closing quotes or brackets, and the whitespace that follows.
 *    Deepgram reads each request as a self-contained piece of text, so ending one on a full stop is the
 *    only split that is inaudible.
 * 2. **After a word.** The last run of whitespace inside the window, which keeps the join at a place the
 *    speaker would have had a gap anyway.
 * 3. **At a character.** Only when a single unbroken run exceeds the whole limit — a pasted URL, a wall
 *    of digits. Splitting mid-token risks an odd pronunciation; dropping the overflow instead would make
 *    the audio a lie about the transcript, so this takes the odd pronunciation.
 *
 * Trailing whitespace stays with the chunk it follows, so the next request starts on a real word rather
 * than a space.
 */
final readonly class TtsTextChunker
{
    /**
     * A sentence ending: terminator, any closing punctuation, and — for the ASCII forms — whitespace.
     *
     * The whitespace is required after `.`, `!` and `?` because those characters are not only sentence
     * terminators. Without it `$12.50` and `4:45` split at the `.`, sending "Your total is $12." and
     * "50 for the order" as two unrelated readings — which is not an awkward pause but a wrong price.
     *
     * It does **not** rescue abbreviations. "Mr. Smith" and "Elm St. today" both match, so a boundary
     * can land after one, and that is accepted rather than fixed: the only fix is a list of known
     * abbreviations, which is per-language, never complete, and wrong in a new way each time it is
     * extended. The cost of getting it wrong is also asymmetric — a split after "Mr." is a slightly long
     * pause and every word still arrives intact, whereas a split inside a number changes what the
     * listener is told. This guards the one that matters and accepts the one that does not.
     *
     * The full-width forms need no such guard — `。` is never a decimal point — and must not require
     * whitespace either, since scripts that use them do not put spaces between words. Without this
     * second alternative such a transcript has no boundary anywhere and falls all the way through to a
     * character split.
     */
    private const SENTENCE_BOUNDARY = '/[.!?\x{2026}][\'"\x{2019}\x{201D}\)\]]*\s+'
        . '|[\x{3002}\x{FF01}\x{FF1F}][\'"\x{2019}\x{201D}\x{300D}\x{FF09}\)\]]*/u';

    private const WHITESPACE_RUN = '/\s+/u';

    /**
     * @return list<string> in order; concatenating them reproduces `$text` exactly
     */
    public function split(string $text, int $maxCharacters): array
    {
        if ($text === '' || $maxCharacters < 1) {
            return $text === '' ? [] : [$text];
        }

        if (mb_strlen($text, 'UTF-8') <= $maxCharacters) {
            return [$text];
        }

        $chunks = [];
        $remaining = $text;

        while (mb_strlen($remaining, 'UTF-8') > $maxCharacters) {
            // Character-safe, so every byte offset found inside it lands on a character boundary and the
            // byte-wise `substr()` calls below cannot cut a multibyte sequence in half.
            $window = mb_substr($remaining, 0, $maxCharacters, 'UTF-8');

            $cut = $this->lastMatchEnd(self::SENTENCE_BOUNDARY, $window)
                ?? $this->lastMatchEnd(self::WHITESPACE_RUN, $window)
                // No sentence and no space in the whole window: one unbroken token. Take the window.
                ?? strlen($window);

            $chunks[] = substr($window, 0, $cut);
            $remaining = substr($remaining, $cut);
        }

        if ($remaining !== '') {
            $chunks[] = $remaining;
        }

        return $chunks;
    }

    /**
     * How many characters would be billed for this text.
     *
     * Counted before chunking, because chunking adds nothing: the sum of the pieces is the whole, which
     * is the same property the split contract guarantees.
     */
    public function characterCount(string $text): int
    {
        return mb_strlen($text, 'UTF-8');
    }

    /**
     * The byte offset just past the last match, or null when there is none — or when the match sits at
     * the very start.
     *
     * A cut of zero would consume nothing and loop forever, so it is reported as "no usable boundary"
     * and the next strategy takes over.
     *
     * @param non-empty-string $pattern
     */
    private function lastMatchEnd(string $pattern, string $window): ?int
    {
        $count = preg_match_all($pattern, $window, $matches, PREG_OFFSET_CAPTURE);

        // `false` is a PCRE failure on a pathological input. Treated exactly like "no boundary here":
        // the next strategy takes over and the text still survives intact, which matters more than
        // finding the prettiest split point.
        if ($count === false || $count === 0) {
            return null;
        }

        /** @var list<array{0: string, 1: int}> $found */
        $found = $matches[0];
        $last = $found[$count - 1];
        $end = $last[1] + strlen($last[0]);

        return $end > 0 ? $end : null;
    }
}
