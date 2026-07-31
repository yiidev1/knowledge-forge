<?php

declare(strict_types=1);

namespace App\Order58\Contract\Exception;

use App\Order58\Contract\Dto\Order58ErrorDetails;
use RuntimeException;
use Throwable;

/**
 * Base for every Order58 Integration API failure the application understands.
 *
 * The {@see Order58ErrorDetails} carries the normalised, already-redacted specifics; the concrete subclass
 * lets a caller react to a class of failure without inspecting a code string. The previous exception is
 * kept for the stack trace but never surfaced to a user, so a raw body or a URL cannot leak through it.
 */
abstract class Order58Exception extends RuntimeException
{
    public function __construct(
        private readonly Order58ErrorDetails $details,
        ?Throwable $previous = null,
    ) {
        parent::__construct($details->safeMessage, 0, $previous);
    }

    public function details(): Order58ErrorDetails
    {
        return $this->details;
    }

    public function isTransient(): bool
    {
        return $this->details->transient;
    }

    public function isSideEffectPossible(): bool
    {
        return $this->details->sideEffectPossible;
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->details->retryAfterSeconds;
    }
}
