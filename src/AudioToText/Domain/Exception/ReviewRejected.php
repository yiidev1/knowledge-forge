<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Exception;

use App\AudioToText\Domain\Speaker\MergeRefusal;
use RuntimeException;

use function sprintf;

/**
 * A correction the system will not perform, with wording written for the administrator who asked.
 *
 * Every message says what was refused and why, because the alternative — quietly doing something
 * approximate — is worse in a feature whose purpose is to record a person's judgement accurately.
 */
final class ReviewRejected extends RuntimeException
{
    public static function noSuchTurn(int $index): self
    {
        return new self(sprintf('That turn is no longer there (position %d). Reload and try again.', $index + 1));
    }

    public static function alreadyThatRole(string $role): self
    {
        return new self(sprintf('That turn is already assigned to the %s.', $role));
    }

    public static function unsupportedRole(): self
    {
        return new self('A turn can only be moved to the Agent or the Customer.');
    }

    public static function splitOutsideText(): self
    {
        return new self('Choose a split point inside the text of the turn.');
    }

    public static function splitWouldEmptyATurn(): self
    {
        return new self('A split has to leave words on both sides.');
    }

    public static function noNeighbourToMerge(): self
    {
        return new self(MergeRefusal::NoNeighbour->reason() ?? 'Those turns cannot be merged.');
    }

    /**
     * The refusal owns its own wording, so a disabled control and the flash that follows a stale page
     * posting anyway say exactly the same thing.
     */
    public static function mergeRefused(MergeRefusal $refusal): self
    {
        return new self($refusal->reason() ?? 'Those turns cannot be merged.');
    }

    public static function emptySelection(): self
    {
        return new self('Select some words to move first.');
    }

    /**
     * The page and the stored turn disagree about what the text says, which means somebody else
     * changed it. Version checking catches most of this; this catches the rest.
     */
    public static function selectionNotFound(): self
    {
        return new self(
            'That selection is no longer part of this turn. Reload the page and select it again.',
        );
    }

    public static function emptyText(): self
    {
        return new self('A turn cannot be left empty. Delete is not available — merge it instead.');
    }

    public static function textUnchanged(): self
    {
        return new self('That text is unchanged.');
    }

    public static function notCompleted(): self
    {
        return new self('Only a completed transcription can be corrected.');
    }

    public static function nothingToReview(): self
    {
        return new self('This recording has no speaker-separated conversation to correct.');
    }

    public static function alreadyConfirmed(): self
    {
        return new self('The speaker roles for this conversation are already confirmed.');
    }

    /**
     * Confirmation asserts a two-sided split, so it must have two sides.
     *
     * Enforced in the domain rather than only on the page: `textFor()` returns an empty string — not
     * null — for a role with no turns, and an empty string reads as "text is present" to the publish
     * gate. A one-sided confirmation would therefore publish an empty Customer or Agent block as
     * though it were a finding.
     */
    public static function rolesIncomplete(): self
    {
        return new self(
            'Roles cannot be confirmed until at least one non-empty turn is assigned to both '
            . 'Agent and Customer.',
        );
    }

    public static function nothingToRevert(): self
    {
        return new self('This conversation has no corrections to undo.');
    }
}
