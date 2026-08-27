<?php

declare(strict_types=1);

namespace App\AudioToText\Application;

/**
 * Whether this tick may claim a job, and if not, why.
 *
 * The reason is carried for the log; the admin page shows only the generic deferral wording, because a
 * server's free memory and load average are not facts a web page needs to publish.
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
