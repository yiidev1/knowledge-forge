<?php

declare(strict_types=1);

namespace App\Shared\Machine;

/**
 * Whether this tick may start work, and if not, why.
 *
 * The reason is carried for the log; an admin surface shows only generic deferral wording, because a
 * server's free memory and load average are not facts a web page needs to publish.
 *
 * Lives in `Shared` because {@see ResourceAdmission} has to return something and it may not name any
 * business module's types. Every worker that asks the question gets the same answer shape.
 */
final readonly class AdmissionDecision
{
    private function __construct(
        public bool $admitted,
        public ?string $reason,
    ) {}

    public static function admit(): self
    {
        return new self(true, null);
    }

    public static function defer(string $reason): self
    {
        return new self(false, $reason);
    }
}
