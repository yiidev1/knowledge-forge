<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Exception;

use RuntimeException;

/**
 * Somebody else corrected this conversation first.
 *
 * Raised when the version a save carried is no longer the current one. The alternative — writing anyway
 * — would silently discard the other person's work, and neither administrator would know.
 */
final class ReviewConflict extends RuntimeException
{
    public static function versionMoved(): self
    {
        return new self(
            'Somebody else corrected this conversation while you were working on it. '
            . 'Reload the page to see their changes, then reapply yours.',
        );
    }
}
