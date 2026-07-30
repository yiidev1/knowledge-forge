<?php

declare(strict_types=1);

namespace App\Ai\Contract\Exception;

use App\Ai\Contract\Dto\AiErrorDetails;
use RuntimeException;
use Throwable;

/**
 * Base for every AI failure the application understands.
 *
 * Provider-neutral: nothing above the OpenAI adapter catches an OpenAI-specific exception. The
 * {@see AiErrorDetails} carries the normalised, already-redacted specifics; the concrete subclass lets
 * a caller react to a class of failure (`catch (AiRateLimited …)`) without inspecting a code string.
 *
 * The previous exception is kept for the stack trace but is never surfaced to a user, so a raw provider
 * message or a URL containing a key cannot leak through it.
 */
abstract class AiException extends RuntimeException
{
    public function __construct(
        private readonly AiErrorDetails $details,
        ?Throwable $previous = null,
    ) {
        parent::__construct($details->safeMessage, 0, $previous);
    }

    public function details(): AiErrorDetails
    {
        return $this->details;
    }

    /**
     * A retry might clear this on its own.
     */
    public function isTransient(): bool
    {
        return $this->details->transient;
    }

    /**
     * The request may already have taken effect, so it must be reconciled rather than blindly retried.
     */
    public function isSideEffectPossible(): bool
    {
        return $this->details->sideEffectPossible;
    }

    public function requestId(): ?string
    {
        return $this->details->requestId;
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->details->retryAfterSeconds;
    }
}
