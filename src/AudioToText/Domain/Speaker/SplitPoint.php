<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Speaker;

use function count;
use function in_array;
use function mb_str_split;
use function trim;

/**
 * A place a turn could be cut, offered to an administrator instead of asking them for a number.
 *
 * A character offset is the only thing the domain can act on, but it is not something a person should
 * ever have to count. So the offsets are computed here from the text itself and rendered as choices in
 * the words — the administrator picks a gap they can see, and the arithmetic stays out of their way.
 *
 * Sentence ends are marked because they are where the mistake being corrected actually happens: the
 * diarizer merges two speakers when one answers immediately, and the seam is almost always a full stop.
 */
final readonly class SplitPoint
{
    private const SENTENCE_ENDS = ['.', '?', '!', '。', '？', '！', '…'];

    public function __construct(
        /** Character offset, in the same units {@see ReviewedTurn::splitAt()} counts. */
        public int $offset,
        /** The word this gap follows, so the choice can be labelled with something readable. */
        public string $after,
        public bool $endsSentence,
    ) {}

    /**
     * Every gap between words, in order.
     *
     * Only gaps with text on both sides are offered: a split has to leave words on either side, and the
     * domain refuses one that would not. Offering a point that is certain to be rejected would be
     * inviting the refusal.
     *
     * @return list<self>
     */
    public static function forText(string $text): array
    {
        $chars = mb_str_split($text);
        $total = count($chars);
        $points = [];

        for ($i = 1; $i < $total; $i++) {
            if (trim($chars[$i]) !== '' || trim($chars[$i - 1]) === '') {
                // Either not whitespace, or not the *start* of a whitespace run — one choice per gap.
                continue;
            }

            $next = $i;
            while ($next < $total && trim($chars[$next]) === '') {
                $next++;
            }

            if ($next >= $total) {
                break; // trailing whitespace: nothing on the far side to split off
            }

            $points[] = new self(
                $i,
                self::wordEndingAt($chars, $i),
                in_array($chars[$i - 1], self::SENTENCE_ENDS, true),
            );
        }

        return $points;
    }

    /**
     * @param list<string> $chars
     */
    private static function wordEndingAt(array $chars, int $gap): string
    {
        $word = '';

        for ($i = $gap - 1; $i >= 0 && trim($chars[$i]) !== ''; $i--) {
            $word = $chars[$i] . $word;
        }

        return $word;
    }
}
