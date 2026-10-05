<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Speaker;

use function preg_replace;
use function trim;

/**
 * The markers a speech engine emits that are not speech.
 *
 * whisper.cpp's token stream carries control markers alongside the words: `[_BEG_]` at the start of a
 * segment and `[_TT_390]`-style timestamp markers between them. They are instructions to a decoder, not
 * anything anybody said, and every one of them that survives into stored text is read on screen, spoken
 * aloud by the voice synthesizer, and hashed into the digest that decides whether generated audio is
 * still current.
 *
 * ## Why this is its own class
 *
 * It has leaked twice. First into the aligned role columns of the reference call, where nine
 * `[_TT_nnn]` markers survived a pattern that required a trailing underscore. Then — through a path
 * that did not exist when that was fixed — into the stored utterances of every deterministic Customer
 * and Agent channel, because single-speaker segmentation bypasses the aligner entirely and so bypassed
 * its private copy of the rule.
 *
 * Two consumers now need the same answer, and the second one proved that a private method is not a
 * place a rule like this can live.
 *
 * ## What it does not do
 *
 * Only these markers are removed. No rewriting, no punctuation normalisation, no case folding, no
 * trimming of the words themselves — stored text has to contain what was said, not a tidied paraphrase
 * of it. The caller decides what to do with a token that was nothing but a marker; {@see strip()}
 * reports that by returning an empty string.
 */
final class TranscriptControlTokens
{
    /**
     * Deliberately not anchored and deliberately not requiring a trailing underscore: whisper emits
     * both `[_BEG_]` and `[_TT_390]`, and a marker can sit anywhere inside a token's text.
     */
    private const PATTERN = '/\[_[A-Z0-9_]+\]/';

    /**
     * The text with every control marker removed, or an empty string when nothing but markers was there.
     *
     * The empty-string answer is the whole contract for a caller deciding whether to keep a token: a
     * marker-only token carries no words and must not become an utterance of its own, nor contribute a
     * timestamp to the pause measurement around it.
     */
    public static function strip(string $text): string
    {
        $cleaned = (string) preg_replace(self::PATTERN, '', $text);

        return trim($cleaned) === '' ? '' : $cleaned;
    }
}
