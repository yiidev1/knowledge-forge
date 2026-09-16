<?php

declare(strict_types=1);

namespace App\AudioToText\Application;

use function preg_replace;

/**
 * Renders the USD "dollars-and" shorthand as a price, for **display of uncorrected machine text only**.
 *
 * ## The measured problem
 *
 * Order-call speakers say a total two ways, and Deepgram treats them differently. Confirmed against
 * three real recordings, using Deepgram's own entity `raw_value` to recover the spoken words:
 *
 * | spoken                          | Deepgram transcript | correct? |
 * |---------------------------------|---------------------|----------|
 * | "27 dollars **and 75 cents**"   | `$27.75`            | yes      |
 * | "15 dollars **and twenty one**" | `$15 and 21`        | no       |
 * | "43 dollars **and forty five**" | `$43 and 45`        | no       |
 *
 * Say "cents" and smart_format produces the decimal. Omit it — the common shorthand — and Deepgram
 * renders the words literally. It is not wrong to do so; it is being faithful. whisper.cpp differs
 * because it *infers* the missing "cents", which is an interpretation rather than a transcription.
 *
 * ## Currency identity comes from the text, never from context
 *
 * The `$` must already be present, and it is there only because Deepgram's smart_format heard the word
 * "dollars". This class never *introduces* a currency symbol: "your total is 43 and 45" stays exactly
 * as it is, because nothing in it says dollars.
 *
 * Only `$` is recognised. `¥ ￥ € £ 元 RMB CNY` and every other marker pass through untouched — this
 * formats a currency, it never converts one. A CJK rule is deliberately absent: no Chinese audio has
 * been processed here, so there is no ground truth to derive a safe one from, and inventing one to
 * claim support would be worse than leaving the seam open.
 *
 * ## Exactly two digits, on evidence
 *
 * The fractional part must be **exactly two digits**. Across 48,514 characters of real transcripts the
 * two-digit form occurs 7 times and the single-digit form **zero** times, so `$43 and 5` has no
 * evidence behind it and three readings ($43.05, $43.50, or "item 5"). Unreadable is better than
 * confidently wrong about a price, so it is left alone.
 *
 * ## Never applied where a human is involved
 *
 * {@see formatUnlessReviewed()} is the authority rule. Beyond it, this must never be applied to a value
 * that reaches an editable field: the review page renders turn text inside `<textarea name="text">`,
 * and normalising it there would persist a display convenience as the human's own correction the
 * moment they pressed Save.
 */
final class SpokenPrice
{
    /**
     * Words and symbols that prove the trailing number is a quantity rather than cents.
     *
     * `minutes` is the one that actually occurs — "$43 and 45 ready in 25 minutes" sits one clause away
     * from a duration. `%` is here because a regression suite caught `$43 and 45%` becoming `$43.45%`:
     * the word `percent` was guarded and the symbol was not. `cents` and `dollars` are present for
     * opposite reasons — "and 45 cents" is already correct and must not be handled twice, and
     * "and 45 dollars" is a second price rather than cents.
     */
    private const UNIT_WORDS = 'cents?|dollars?|bucks|minutes?|mins?|hours?|people|persons?|orders?'
        . '|pieces?|items?|each|percent|miles?|pounds?|ounces?|days?|weeks?';

    /**
     * `$43 and 45` becomes `$43.45`; everything else is returned byte for byte.
     *
     * A plain replacement rather than a callback: requiring exactly two digits removes the only reason
     * there was ever arithmetic here, because there is no longer a single digit to zero-pad.
     */
    public static function format(string $text): string
    {
        $pattern = '/'
            . '(?<![\w.,])'                   // not mid-number and not after a decimal point
            . '\$(\d{1,4})'                   // dollars — the $ smart_format added after hearing "dollars"
            . '\s+and\s+'                     // the shorthand joiner
            . '(?!\$)'                        // a second $ means two prices, not cents
            . '(\d{2})'                       // cents: EXACTLY two digits. See the class docblock.
            . '(?!\s*(?:' . self::UNIT_WORDS . ')\b)'  // …not a quantity
            . '(?!\s*%)'                      // …and not a percentage
            // The two digits must END there. Anything word-like immediately after means this was never
            // a cents value: a measurement ("45kg", "45lbs", "45oz"), an ordinal ("45th"), an
            // identifier ("45A", "45_1", "45abc") or a range ("45-50"). `\w` covers letters, digits and
            // underscore; the hyphen is added for ticket and SKU ranges.
            . '(?![\w-])'
            // And not the head of a longer number. A trailing `.` or `,` only disqualifies the match
            // when a digit follows it — "$43 and 45." ends a sentence, while "$43 and 45.50" and
            // "$43 and 45,000" are numbers this class does not understand and must not touch.
            . '(?![.,]\d)'
            . '/iu';

        // A PCRE failure (catastrophic backtracking on a pathological input) must never lose the
        // transcript, so the original is the fallback.
        return preg_replace($pattern, '\$$1.$2', $text) ?? $text;
    }

    /**
     * The authority rule: **an explicit human correction always wins.**
     *
     * Automatic formatting is a convenience for text nobody has looked at yet. Once an administrator
     * has saved a correction, what they wrote is what must be shown — including when they deliberately
     * left "$43 and 45" alone, which this would otherwise silently overrule.
     *
     * The flag comes from {@see \App\AudioToText\Domain\EffectiveConversation::$isReviewed}, which
     * {@see EffectiveConversationReader} already derives from the stored reviewed layer, so callers
     * read one decision rather than each re-deriving it.
     */
    public static function formatUnlessReviewed(string $text, bool $isReviewed): string
    {
        return $isReviewed ? $text : self::format($text);
    }
}
