<?php

declare(strict_types=1);

namespace App\Chat\Domain\Exception;

use App\Shared\Domain\Exception\DomainException;

use function sprintf;

/**
 * The submitted question is empty or longer than the configured limit. Guarded server-side so a crafted
 * request cannot bypass the form's `maxlength`.
 */
final class QuestionInvalid extends DomainException
{
    public function errorCode(): string
    {
        return 'question_invalid';
    }

    public static function empty(): self
    {
        return new self('Enter a question to ask.');
    }

    public static function tooLong(int $maxLength): self
    {
        return new self(sprintf('That question is too long. Keep it under %d characters.', $maxLength));
    }
}
