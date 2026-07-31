<?php

declare(strict_types=1);

namespace App\Order58\Client;

use App\Shared\Domain\Exception\ConfigurationException;
use App\Shared\Domain\ValueObject\SecretValue;
use SensitiveParameter;

use function rtrim;
use function str_starts_with;

/**
 * The single, shared Order58 Integration API credential: one Bearer token plus the base URL. Configured
 * from `ORDER58_API_TOKEN` / `ORDER58_API_BASE_URL` in one place, so changing the token there updates
 * every call. The token is wrapped in a {@see SecretValue} and revealed only when the Authorization
 * header is built — it never reaches a log, a template, a database row or a stack trace.
 */
final readonly class Order58Credentials
{
    public SecretValue $token;
    public string $baseUrl;

    public function __construct(
        #[SensitiveParameter]
        string $token,
        string $baseUrl,
    ) {
        $this->token = new SecretValue($token);
        $this->baseUrl = rtrim($baseUrl, '/');

        if ($this->baseUrl !== '' && !str_starts_with($this->baseUrl, 'https://') && !str_starts_with($this->baseUrl, 'http://')) {
            throw ConfigurationException::invalid('ORDER58_API_BASE_URL', 'an absolute http(s) URL');
        }
    }

    public function isComplete(): bool
    {
        return !$this->token->isEmpty() && $this->baseUrl !== '';
    }

    /**
     * @return list<string>
     */
    public function missingVariables(): array
    {
        $missing = [];

        if ($this->token->isEmpty()) {
            $missing[] = 'ORDER58_API_TOKEN';
        }

        if ($this->baseUrl === '') {
            $missing[] = 'ORDER58_API_BASE_URL';
        }

        return $missing;
    }

    /**
     * @throws ConfigurationException when a value required to make a request is absent.
     */
    public function assertComplete(): void
    {
        if ($this->token->isEmpty()) {
            throw ConfigurationException::missing('ORDER58_API_TOKEN', 'authenticate against the Order58 Integration API');
        }

        if ($this->baseUrl === '') {
            throw ConfigurationException::missing('ORDER58_API_BASE_URL', 'reach the Order58 Integration API');
        }
    }
}
