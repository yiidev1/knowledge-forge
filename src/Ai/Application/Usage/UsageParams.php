<?php

declare(strict_types=1);

namespace App\Ai\Application\Usage;

/**
 * Resolved tuning for the usage dashboard, built once from configuration and injected.
 *
 * `$adminApiConfigured` is a plain bool on purpose. The dashboard only ever needs to know WHETHER an
 * organization key exists so it can label those sections "Unavailable"; it must never hold, pass around
 * or be able to leak the key itself. The key stays inside the credential object in the DI container.
 */
final readonly class UsageParams
{
    public function __construct(
        /** Wall-clock seconds a single sync may spend calling the provider. */
        public int $budgetSeconds = 45,
        /** Minimum gap between sync attempts, enforced on every attempt including failed ones. */
        public int $throttleSeconds = 10,
        public bool $adminApiConfigured = false,
    ) {}
}
