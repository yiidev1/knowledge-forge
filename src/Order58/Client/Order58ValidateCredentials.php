<?php

declare(strict_types=1);

namespace App\Order58\Client;

use App\Shared\Domain\ValueObject\SecretValue;
use SensitiveParameter;

use function rtrim;
use function str_starts_with;

/**
 * The single, shared credential for the Order58 fallback validate API: one static Bearer token plus the full
 * endpoint URL. Configured from `ORDER58_VALIDATE_API_TOKEN` / `ORDER58_VALIDATE_API_URL` in one place, so
 * changing the token there updates every call.
 *
 * Deliberately separate from {@see Order58Credentials}: this is a different host and a different API with a
 * single operation, and its token is a different secret. Sharing one object would mean a token rotation on
 * one API silently changed the other.
 *
 * A URL that is not absolute http(s) is treated as *not configured* rather than as a fatal error — the whole
 * fallback is optional, and a malformed value must degrade to "the fallback is off", never to a broken login
 * page. {@see \App\Order58\Client\HttpOrder58CredentialValidator} reports that as `NotConfigured`.
 *
 * The token is wrapped in a {@see SecretValue} and revealed only when the Authorization header is built — it
 * never reaches a log, a template, a database row or a stack trace.
 */
final readonly class Order58ValidateCredentials
{
    public SecretValue $token;
    public string $url;

    public function __construct(
        #[SensitiveParameter]
        string $token,
        string $url,
    ) {
        $this->token = new SecretValue($token);
        $this->url = rtrim($url, '/');
    }

    public function isComplete(): bool
    {
        return !$this->token->isEmpty() && $this->hasAbsoluteUrl();
    }

    /**
     * The names of the variables an operator still has to set. Names only — never values.
     *
     * @return list<string>
     */
    public function missingVariables(): array
    {
        $missing = [];

        if ($this->token->isEmpty()) {
            $missing[] = 'ORDER58_VALIDATE_API_TOKEN';
        }

        if (!$this->hasAbsoluteUrl()) {
            $missing[] = 'ORDER58_VALIDATE_API_URL';
        }

        return $missing;
    }

    private function hasAbsoluteUrl(): bool
    {
        return str_starts_with($this->url, 'https://') || str_starts_with($this->url, 'http://');
    }
}
