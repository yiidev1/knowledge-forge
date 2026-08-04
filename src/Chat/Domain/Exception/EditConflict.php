<?php

declare(strict_types=1);

namespace App\Chat\Domain\Exception;

/**
 * A concurrent edit won the optimistic lock: the question's edit_count no longer matches the value the
 * editor started from. A conflict (409 in semantics), surfaced as a flash so the user can reload and retry.
 */
final class EditConflict extends MessageEditException
{
    public function errorCode(): string
    {
        return 'edit_conflict';
    }

    public static function staleEditCount(): self
    {
        return new self('This question was changed in another session. Reload the chat and try your edit again.');
    }
}
