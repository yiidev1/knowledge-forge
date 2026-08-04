<?php

declare(strict_types=1);

namespace App\Chat\Domain\Exception;

use App\Shared\Domain\Exception\DomainException;

/**
 * A new question was submitted while the thread's latest question still has no active answer — its
 * regeneration is in flight or failed. Blocking here (server-side, not just in the UI) is what prevents an
 * unanswered question from being pushed into a non-latest position and stranded: the user must let the
 * pending answer finish or use Retry first. Surfaced as a flash on the ask flow.
 */
final class LatestQuestionUnanswered extends DomainException
{
    public function errorCode(): string
    {
        return 'latest_question_unanswered';
    }

    public static function create(): self
    {
        return new self('Your previous question is still being answered. Wait for it to finish, or use Retry, before asking a new one.');
    }
}
