<?php

declare(strict_types=1);

namespace App\Chat\Domain\Exception;

/**
 * The edited question is identical to the current one after normalization. Rejected so an edit never
 * needlessly supersedes a good answer and re-bills a regeneration that would produce the same result.
 */
final class UnchangedContent extends MessageEditException
{
    public function errorCode(): string
    {
        return 'unchanged_content';
    }

    public static function create(): self
    {
        return new self('The edited question is the same as the current one. Change the text or cancel.');
    }
}
