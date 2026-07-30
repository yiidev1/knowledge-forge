<?php

declare(strict_types=1);

namespace App\Shared\Application\Health;

use function array_map;
use function array_values;

final readonly class HealthReport
{
    /**
     * @param list<HealthCheck> $checks
     */
    public function __construct(
        public array $checks,
        /**
         * Digest of the fully resolved, secret-free configuration.
         *
         * Run the health check as the deployment user and again as the web/cron user: the two
         * fingerprints must match. A mismatch is the signature of the classic outage where cron reads a
         * different `.env` than PHP-FPM, or where a stale FPM pool `env[]` block overrides the file.
         */
        public string $configFingerprint,
        public string $environment,
    ) {}

    /**
     * Returns a copy with extra checks appended, so a caller that knows about a context-specific check
     * (pending migrations, which only makes sense on the console) can fold it into the same verdict.
     */
    public function with(HealthCheck ...$checks): self
    {
        return new self(
            array_values([...$this->checks, ...$checks]),
            $this->configFingerprint,
            $this->environment,
        );
    }

    public function status(): HealthStatus
    {
        $status = HealthStatus::Ok;

        foreach ($this->checks as $check) {
            $status = $status->worseOf($check->status);
        }

        return $status;
    }

    public function isHealthy(): bool
    {
        return $this->status() !== HealthStatus::Failure;
    }

    /**
     * @return array{status: string, environment: string, config_fingerprint: string, checks: list<array{name: string, status: string, message: string, detail: string|null}>}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status()->value,
            'environment' => $this->environment,
            'config_fingerprint' => $this->configFingerprint,
            'checks' => array_map(static fn(HealthCheck $c): array => $c->toArray(), $this->checks),
        ];
    }
}
