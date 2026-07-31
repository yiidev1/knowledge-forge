<?php

declare(strict_types=1);

namespace App\Document\Domain\Exception;

use function sprintf;

/**
 * A file presented as text is not valid UTF-8, or a manual/text document is empty, over the length limit,
 * or missing a title. Rejecting non-UTF-8 content keeps binary files (and mojibake) out of the index.
 */
final class InvalidText extends UploadException
{
    public function errorCode(): string
    {
        return 'invalid_text';
    }

    public static function notUtf8(): self
    {
        return new self('This file is not valid UTF-8 text. Save it as UTF-8 and try again.');
    }

    public static function empty(): self
    {
        return new self('There is no text to save.');
    }

    public static function titleRequired(): self
    {
        return new self('Enter a title.');
    }

    public static function tooLong(int $maxCharacters): self
    {
        return new self(sprintf('The text is too long. The limit is %d characters.', $maxCharacters));
    }
}
