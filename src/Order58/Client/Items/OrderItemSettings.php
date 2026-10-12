<?php

declare(strict_types=1);

namespace App\Order58\Client\Items;

use SensitiveParameter;

/**
 * Where the Order Items API is, how it authenticates, and what it costs us to wait for it.
 *
 * ## One credential, shared with the Orders endpoint
 *
 * Confirmed by the client and proven by a live request: this endpoint takes the **same token** as
 * `/api/order-data/list`, and both live on order58.com. So `$token` normally arrives from
 * `ORDER58_ORDERS_API_TOKEN` — `config/common/params.php` falls back to it, and
 * `ORDER58_ITEM_API_TOKEN` is an override that is empty by default.
 *
 * Writing the same secret into two variables would mean two places to rotate it and one of them going
 * stale unnoticed, which is why the fallback exists rather than a second copy. If the provider ever
 * issues an items-specific credential, setting the override takes precedence with no code change.
 *
 * That is also why {@see problems()} names `ORDER58_ORDERS_API_TOKEN` first: by the time `$token` is
 * empty, BOTH variables are unset, and pointing an administrator at the override would produce the
 * duplication this arrangement avoids.
 *
 * ## The scheme is configurable, and `Bearer` is confirmed
 *
 * `Bearer` is the default and is what the orders endpoint uses; a live request confirmed the items
 * endpoint accepts the identical header. `ORDER58_ITEM_API_AUTH_SCHEME` can still be set to anything,
 * including an empty string for an endpoint that takes the token bare — see {@see authorizationHeader()}.
 *
 * ## Enabled is separate from configured
 *
 * {@see isEnabled()} is the kill switch and defaults to **off**, so merging this code sends no traffic.
 * {@see isUsable()} asks the different question of whether the values are present. Both must be true
 * before a request is made, and the two are kept apart so an operator can tell "switched off" from
 * "never set up".
 */
final readonly class OrderItemSettings
{
    public function __construct(
        public string $url,
        #[SensitiveParameter]
        private string $token,
        /** `Bearer` — confirmed against the live endpoint. Empty sends the token with no prefix. */
        private string $authScheme,
        public int $connectTimeoutSeconds,
        public int $timeoutSeconds,
        public int $maxResponseBytes,
        private bool $enabled,
    ) {}

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

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

        if (!$this->enabled) {
            $problems[] = 'ORDER58_ITEM_SYNC_ENABLED is false.';
        }
        if ($this->url === '') {
            $problems[] = 'ORDER58_ITEM_API_URL is not set.';
        }
        if ($this->token === '') {
            // Names the variable that actually supplies the credential, not the override.
            //
            // `ORDER58_ITEM_API_TOKEN` is empty by design: `config/common/params.php` falls back to
            // `ORDER58_ORDERS_API_TOKEN`, because the two endpoints share one token. An empty token
            // here therefore means BOTH are unset — and a message naming only the override would send
            // an administrator to set it, creating exactly the duplicated secret the fallback exists
            // to avoid.
            $problems[] = 'No Order58 API token is configured. Set ORDER58_ORDERS_API_TOKEN '
                . '(shared with the Orders endpoint), or ORDER58_ITEM_API_TOKEN to override it.';
        }

        return $problems;
    }

    /**
     * The single place the token is revealed.
     *
     * An empty scheme sends the token on its own, which is how some providers expect it. A scheme is
     * never guessed from the response — if the provider rejects this, the scheme is configuration to
     * change, not something to probe for.
     */
    public function authorizationHeader(): string
    {
        return $this->authScheme === '' ? $this->token : $this->authScheme . ' ' . $this->token;
    }
}
