<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain;

use RuntimeException;

/**
 * Somebody else changed this order's verification while this request was being composed.
 *
 * Thrown rather than resolved, because there is no safe way to resolve it here: the writer was looking
 * at a page that said something different, and applying their click anyway would undo a decision they
 * never saw. The caller answers with the current state so the screen can catch up and the person can
 * decide again, knowingly.
 */
final class VerificationConflict extends RuntimeException
{
    public function __construct(public readonly OrderVerification $current)
    {
        parent::__construct(
            'This order\'s verification was changed by somebody else while you were looking at it.',
        );
    }
}
