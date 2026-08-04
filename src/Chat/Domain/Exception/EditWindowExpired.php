<?php

declare(strict_types=1);

namespace App\Chat\Domain\Exception;

use function sprintf;

/**
 * The question is past its edit window (measured from its original created_at on the server clock). Not a
 * 404 — the message exists and is the latest; the user is simply too late, so it is surfaced as a flash.
 */
final class EditWindowExpired extends MessageEditException
{
    public function errorCode(): string
    {
        return 'edit_window_expired';
    }

    public static function after(int $minutes): self
    {
        return new self(sprintf(
            'This question can no longer be edited. Questions are editable for %d minutes after they are asked.',
            $minutes,
        ));
    }
}
