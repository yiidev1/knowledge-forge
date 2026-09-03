<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Speaker;

use function count;
use function json_encode;
use function min;

/**
 * What one correction changed, derived by comparing the conversation before it with the one after.
 *
 * Turns carry no identity — no id, no lineage key — and their only handle is a position that shifts the
 * moment a split or a merge changes how many there are. So "which message did this operation touch" is
 * not recorded anywhere and has to be *derived*.
 *
 * It can be, exactly, because of a property every operation has: each one replaces a single contiguous
 * run of turns and leaves every other turn byte-identical. Trimming the shared prefix and the shared
 * suffix therefore isolates precisely the run that changed, on both sides. No text matching, no
 * position guessing, no heuristics.
 *
 * That property is not an assumption. It holds by construction — the operations rebuild the turn list
 * around one edited region and copy the rest — it is asserted for every operation by
 * `ReviewedConversationTurnsContiguityTest`, and it was verified against all 112 recorded corrections
 * in this installation, where the changed run was:
 *
 *   EDIT_TEXT 1→1 · MOVE 1→1 or 1→3 · SPLIT 1→2 · MERGE 2→1 or 2→2 · CONFIRM_ROLES 0→0
 *
 * A 0→0 run means the operation changed no turn at all, which is what every confirmation does.
 */
final readonly class ConversationDiff
{
    private function __construct(
        /** Turns before this index are identical on both sides. */
        public int $prefix,
        /** How many turns the run covered beforehand. */
        public int $lengthBefore,
        /** How many it covers afterwards. */
        public int $lengthAfter,
    ) {}

    /**
     * @param list<ReviewedTurn> $before
     * @param list<ReviewedTurn> $after
     */
    public static function between(array $before, array $after): self
    {
        $a = self::fingerprints($before);
        $b = self::fingerprints($after);

        $prefix = 0;
        while ($prefix < count($a) && $prefix < count($b) && $a[$prefix] === $b[$prefix]) {
            $prefix++;
        }

        // Bounded by what the prefix already claimed, so the two runs can never overlap it — without
        // that, two identical adjacent turns would let the suffix walk back past the prefix and report
        // a negative-length run.
        $maxSuffix = min(count($a) - $prefix, count($b) - $prefix);
        $suffix = 0;
        while ($suffix < $maxSuffix) {
            $i = count($a) - 1 - $suffix;
            $j = count($b) - 1 - $suffix;

            if ($i < 0 || $j < 0 || $a[$i] !== $b[$j]) {
                break;
            }

            $suffix++;
        }

        return new self($prefix, count($a) - $prefix - $suffix, count($b) - $prefix - $suffix);
    }

    /** Nothing was structurally changed — every confirmation, and any operation that was a no-op. */
    public function isEmpty(): bool
    {
        return $this->lengthBefore === 0 && $this->lengthAfter === 0;
    }

    /**
     * Every persisted field, in a fixed order.
     *
     * All eight, not just the text: a whole-turn move changes only the role, and a split marks both
     * halves approximate without touching a word. Comparing anything less would report those as no
     * change at all.
     *
     * @param list<ReviewedTurn> $turns
     *
     * @return list<string>
     */
    private static function fingerprints(array $turns): array
    {
        $out = [];
        foreach ($turns as $turn) {
            $out[] = (string) json_encode([
                $turn->startMs,
                $turn->endMs,
                $turn->speaker,
                $turn->role->value,
                $turn->text,
                $turn->confidence,
                $turn->approx,
                $turn->edited,
            ]);
        }

        return $out;
    }
}
