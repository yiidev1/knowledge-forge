<?php

declare(strict_types=1);

namespace App\Order58\Client\Orders;

use SensitiveParameter;

/**
 * Where the Orders API is and what it costs us to wait for it.
 *
 * A separate endpoint from the accounts/sync API, with its own host, verb and credential — the same
 * arrangement {@see \App\Order58\Client\Order58ValidateCredentials} already has, and for the same reason:
 * one endpoint, one credential, one place to rotate it.
 *
 * The token is a constructor argument marked sensitive so it never appears in a stack trace, and it is
 * read exactly once, in {@see HttpOrderDataClient}, to build the Authorization header.
 */
final readonly class OrderDataSettings
{
    public function __construct(
        public string $url,
        #[SensitiveParameter]
        private string $token,
        public int $connectTimeoutSeconds,
        public int $timeoutSeconds,
        public int $maxResponseBytes,
    ) {}

    /** Usable means configured — checked without opening a socket. */
    public function isUsable(): bool
    {
        return $this->url !== '' && $this->token !== '';
    }

    /**
     * The variable names an administrator would have to set. **Names only, never values.**
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];
        if ($this->url === '') {
            $problems[] = 'ORDER58_ORDERS_API_URL is not set.';
        }
        if ($this->token === '') {
            $problems[] = 'ORDER58_ORDERS_API_TOKEN is not set.';
        }

        return $problems;
    }

    /** The single place the token is revealed. */
    public function authorizationHeader(): string
    {
        return 'Bearer ' . $this->token;
    }
}
