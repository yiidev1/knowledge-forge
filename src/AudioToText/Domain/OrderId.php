<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

use function preg_match;
use function sprintf;
use function trim;

/**
 * The order an uploaded recording belongs to, as the person uploading it typed it.
 *
 * ## A reference, not a foreign key
 *
 * Nothing in this application resolves it, joins on it or checks it exists. The Order58 mirror holds
 * stores, agents, knowledge records and rules — **no orders** — so there is nothing here to point at,
 * and inventing a relationship would be claiming a guarantee this schema cannot make. It is recorded
 * so the store's history can show which call an operator was working on, and that is all it does.
 *
 * ## Optional, and absence is a real answer
 *
 * A recording uploaded without one is a normal recording. Absence is stored as NULL — never as `0` or
 * an empty string, which would both be values that look like answers. The history prints an em dash.
 *
 * ## Digits only, bounded at twenty
 *
 * Every order identifier this project has seen is numeric (`16513791`), and digits-only is also what
 * makes the value safe wherever it is echoed. Twenty digits is the bound the rest of the application
 * already uses for an external numeric id it does not own — generous enough that no plausible order
 * number is refused, small enough that a paste of something else is.
 *
 * The value is kept as a **string**, not an int: it is an identifier rather than a quantity, nothing
 * arithmetic is ever done with it, and a 20-digit value does not fit in a signed 64-bit integer.
 */
final readonly class OrderId
{
    /** The bound this project already uses elsewhere for an external numeric id it does not own. */
    public const MAX_DIGITS = 20;

    /**
     * What is wrong with this input, or null when there is nothing wrong with it.
     *
     * **Blank passes.** That is the whole of the "optional" rule, stated once, here, rather than left
     * to each caller to remember.
     */
    public static function validate(string $raw): ?string
    {
        $value = trim($raw);

        if ($value === '') {
            return null;
        }

        return preg_match('/\A\d{1,' . self::MAX_DIGITS . '}\z/', $value) === 1
            ? null
            : sprintf(
                'Order ID must be digits only, up to %d of them — for example 16513791. Leave it empty '
                . 'if this recording has no order.',
                self::MAX_DIGITS,
            );
    }

    /**
     * The value to store, or null when none was given.
     *
     * Call it only after {@see validate()} has returned null. Anchored with `\A`/`\z` rather than
     * `^`/`$` in the validator, because `$` also matches before a trailing newline — so this cannot be
     * handed something that passed validation on its first line alone.
     */
    public static function fromInput(string $raw): ?string
    {
        $value = trim($raw);

        return $value === '' ? null : $value;
    }
}
