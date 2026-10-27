<?php

declare(strict_types=1);

namespace App\Order58\Client\CaseOrders;

use DateTimeZone;
use Exception;

use function filter_var;
use function trim;

use const FILTER_FLAG_PATH_REQUIRED;
use const FILTER_VALIDATE_URL;

/**
 * Everything the Case Order endpoint needs, in one validated object.
 *
 * ## One endpoint, and deliberately its own settings
 *
 * It would have been shorter to reuse {@see \App\Order58\Client\Orders\OrderDataSettings}: same host,
 * and today the same token. They are kept apart because they are not the same contract — this one is
 * limited to one call a day, carries its items inline, and authenticates in a way the other does not.
 * Sharing the object would mean a change made for one silently applying to the other.
 *
 * ## Nothing here is logged or rendered
 *
 * The token appears in exactly two places: {@see authorizedUrl()} and {@see authorizationHeader()}.
 * Neither is logged, and `__toString`/`var_dump` of this object would show it — so it is never put in
 * an exception message, a template variable or a progress response. The daily-sync screen reports
 * status and counts, never configuration.
 */
final readonly class CaseOrderSettings
{
    /**
     * How long to wait for a response.
     *
     * Generous on purpose: the sample response is 1.18 MB and the provider took 1.371 s in a Postman
     * capture, but this is one call a day over a link nobody controls. A timeout here does NOT get
     * retried — the allowance is spent either way — so it is set long enough that a slow answer is
     * received rather than abandoned.
     */
    public const TIMEOUT_SECONDS = 120;

    /**
     * The largest response this will read.
     *
     * 200 orders with their items measured 1.18 MB. 16 MB is an order of magnitude of headroom and
     * still a ceiling, so a provider fault cannot exhaust memory on a machine that also serves pages.
     */
    public const MAX_RESPONSE_BYTES = 16 * 1024 * 1024;

    /** The client's own calendar. Used when the setting is blank or not a zone PHP knows. */
    public const DEFAULT_TIMEZONE = 'America/New_York';

    public function __construct(
        /** The full endpoint, without credentials. */
        public string $url,
        /** The provider's credential. Never rendered, never logged. */
        public string $token,
        /** Which authentication the provider wants. See {@see CaseOrderAuth}. */
        public CaseOrderAuth $auth = CaseOrderAuth::Query,
        /**
         * The calendar the one-a-day allowance is counted against.
         *
         * Explicit and configurable because "today" is a business decision, not a server fact: the
         * client's day is not UTC's, and a sync at 23:30 local must not be allowed to run again
         * forty minutes later because a different clock rolled over.
         */
        public string $timezone = self::DEFAULT_TIMEZONE,
    ) {}

    /**
     * The business calendar, as a zone object.
     *
     * Falls back to the default rather than throwing on an unset or blank setting: the day boundary
     * is a rationing rule, and refusing to compute one would turn a bad config value into "the sync
     * can never run" instead of "it runs on New York days".
     */
    public function businessTimeZone(): DateTimeZone
    {
        $zone = trim($this->timezone);

        try {
            return new DateTimeZone($zone === '' ? self::DEFAULT_TIMEZONE : $zone);
        } catch (Exception) {
            return new DateTimeZone(self::DEFAULT_TIMEZONE);
        }
    }

    /** Whether this is configured well enough to attempt a call at all. */
    public function isUsable(): bool
    {
        return $this->problems() === [];
    }

    /**
     * What is wrong with this configuration, in words an operator can act on.
     *
     * Checked before the allowance is claimed, so a missing token costs a message rather than the
     * day's only request.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];

        if (trim($this->url) === '') {
            $problems[] = 'The Case Order API URL is not configured (ORDER58_CASE_ORDER_API_URL).';
        } elseif (filter_var($this->url, FILTER_VALIDATE_URL, FILTER_FLAG_PATH_REQUIRED) === false) {
            $problems[] = 'The Case Order API URL is not a valid absolute URL.';
        } elseif (!str_starts_with($this->url, 'https://')) {
            // Plain HTTP would put the credential and a customer's order on the wire in clear.
            $problems[] = 'The Case Order API URL must use HTTPS.';
        }

        if (trim($this->token) === '') {
            $problems[] = 'The Case Order API token is not configured (ORDER58_CASE_ORDER_API_TOKEN).';
        }

        return $problems;
    }

    /**
     * The URL to call, with the credential attached when that is the configured mode.
     *
     * The token is appended rather than interpolated into a format string so a URL that already
     * carries a query string keeps it.
     */
    public function authorizedUrl(): string
    {
        if ($this->auth !== CaseOrderAuth::Query) {
            return $this->url;
        }

        $separator = str_contains($this->url, '?') ? '&' : '?';

        return $this->url . $separator . 'access-token=' . rawurlencode($this->token);
    }

    /** The header to send, or null when the credential travels in the query string instead. */
    public function authorizationHeader(): ?string
    {
        return $this->auth === CaseOrderAuth::Bearer ? 'Bearer ' . $this->token : null;
    }

    /**
     * The URL as it may appear in a log or an error.
     *
     * The credential is removed rather than masked in place: a masked token still tells a reader how
     * long it is and where it starts.
     */
    public function safeUrl(): string
    {
        return $this->url;
    }
}
