<?php

declare(strict_types=1);

namespace App\Chat\Domain\Exception;

use App\Shared\Domain\Exception\DomainException;

use function sprintf;

/**
 * The submitted score is not an integer 1–10, or a dismissal was posted for an answer that already carries
 * a score.
 *
 * Recoverable and user-facing (flash + PRG), unlike a forged target which is a 404. The range is guarded
 * here rather than trusted from the browser: the slider's `min`/`max` are a convenience, and a crafted POST
 * bypasses them entirely.
 */
final class AnswerScoreInvalid extends DomainException
{
    public function errorCode(): string
    {
        return 'answer_score_invalid';
    }

    /**
     * Covers every non-integer shape as well as out-of-range values — `8abc`, `8.5`, `""`, `0` and `11` are
     * all rejected rather than coerced to something plausible.
     */
    public static function outOfRange(): self
    {
        return new self('Choose a score between 1 and 10.');
    }

    /**
     * A dismissal cannot silently discard an existing rating. The rated UI only offers "Change", so this is
     * reachable only by a stale page or a crafted request; either way the saved score is left untouched.
     */
    public static function alreadyRated(): self
    {
        return new self('This answer already has a score. Use Change to update it.');
    }

    /**
     * The note explaining a low score is optional, but it is stored in a bounded column and the length is
     * enforced here rather than trusted from the browser's `maxlength`.
     */
    public static function commentTooLong(int $max): self
    {
        return new self(sprintf('Keep the comment under %d characters.', $max));
    }
}
