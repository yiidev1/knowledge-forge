<?php

declare(strict_types=1);

namespace App\Shared\Application\Health;

/**
 * One diagnostic result.
 *
 * `$detail` is shown to an operator, so it must already be free of credentials. Nothing in this class
 * redacts anything: callers pass values that are safe by construction, such as a connection
 * description without a password or a secret's digest.
 */
final readonly class HealthCheck
{
    public function __construct(
        public string $name,
        public HealthStatus $status,
        public string $message,
        public ?string $detail = null,
    ) {}

    public static function ok(string $name, string $message, ?string $detail = null): self
    {
        return new self($name, HealthStatus::Ok, $message, $detail);
    }

    public static function warning(string $name, string $message, ?string $detail = null): self
    {
        return new self($name, HealthStatus::Warning, $message, $detail);
    }

    public static function failure(string $name, string $message, ?string $detail = null): self
    {
        return new self($name, HealthStatus::Failure, $message, $detail);
    }

    /**
     * @return array{name: string, status: string, message: string, detail: string|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'status' => $this->status->value,
            'message' => $this->message,
            'detail' => $this->detail,
        ];
    }
}
