<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain;

/**
 * Where one test attempt has got to.
 *
 * `STARTED` and `MATCHED` are both **open**: an attempt that has already produced one demo order is
 * still the attempt an operator is working inside, and the next order they place during the same session
 * belongs to it too. Production proved the shape — one source order produced three demo orders — so an
 * attempt that closed on its first match would credit one of them and orphan two.
 *
 * `EXPIRED` and `CANCELLED` are terminal and release the slot. Nothing ever moves back out of them.
 */
enum AttemptStatus: string
{
    /** Clicked through to Order58; nothing has come back yet. */
    case Started = 'STARTED';

    /** At least one demo order has been imported and credited to it. Still open. */
    case Matched = 'MATCHED';

    /** Its window passed without being closed. Released automatically, never retried. */
    case Expired = 'EXPIRED';

    /** Closed deliberately. Reserved for a future "give up" control; nothing writes it today. */
    case Cancelled = 'CANCELLED';

    /** Whether this status still holds the one slot for its store and source order. */
    public function isOpen(): bool
    {
        return $this === self::Started || $this === self::Matched;
    }

    public function label(): string
    {
        return match ($this) {
            self::Started => 'In progress',
            self::Matched => 'Order created',
            self::Expired => 'Expired',
            self::Cancelled => 'Cancelled',
        };
    }
}
