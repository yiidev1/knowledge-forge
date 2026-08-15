<?php

declare(strict_types=1);

namespace App\Agent\Application;

use App\Agent\Domain\AgentIdentity;

/**
 * What the fallback path concluded, in the three shapes {@see AgentLoginService} has to act on.
 *
 * The distinction that matters is `rejected` versus `unavailable`. A rejection is a statement about the
 * submitted password and costs the sender one throttle failure; unavailability is a statement about our own
 * integration and must cost nothing, or an outage would lock legitimate agents out of their own accounts.
 */
final readonly class FallbackAgentAuthentication
{
    private function __construct(
        public ?AgentIdentity $agent,
        public bool $unavailable,
        public ?string $message,
    ) {}

    public static function admitted(AgentIdentity $agent): self
    {
        return new self($agent, false, null);
    }

    /** The credentials are not valid, or they are valid but belong to nobody we may admit. */
    public static function rejected(): self
    {
        return new self(null, false, null);
    }

    /**
     * We could not obtain a verdict. `$message` is an already-sanitized upstream string to show instead of
     * the generic wording, when the API supplied one worth showing.
     */
    public static function unavailable(?string $message = null): self
    {
        return new self(null, true, $message);
    }
}
