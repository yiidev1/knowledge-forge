<?php

declare(strict_types=1);

namespace App\Order58\Client\CaseOrders;

use RuntimeException;

use function number_format;

/**
 * The day's Case Order request did not produce a usable response.
 *
 * ## Every message here is this application's own words
 *
 * None of them carries a provider body, a transport exception's text, or a URL. All three can hold the
 * credential or a customer's order, and these messages are stored in `error_summary` and rendered on
 * an admin page — so the sanitising happens at construction, where it cannot be forgotten, rather
 * than at each of the places that display them.
 *
 * ## `spentTheAllowance` is the field that matters
 *
 * After a failure the only question worth asking is whether the request was actually made, because
 * that is what the history has to record honestly and what decides whether the local half can be
 * retried without asking the provider again. A refusal before the socket — not configured, switched
 * off — costs nothing. A refusal the provider sent, or a body that could not be read, was paid for.
 */
final class CaseOrderFetchFailed extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly bool $spentTheAllowance,
        public readonly ?int $httpStatus = null,
        /**
         * Seconds the PROVIDER asked us to wait, when it said so. Null when it did not.
         *
         * Only ever set from a `Retry-After` header on a 429. It is not a policy of ours and not a
         * guess: an absent header stays null rather than becoming a default, because inventing a
         * number here would be this application rationing itself again under the provider's name.
         */
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }

    public static function notConfigured(string $detail): self
    {
        return new self('The Case Order API is not configured. ' . $detail, false);
    }

    /** Nothing was sent, so nothing was spent. */
    public static function unreachable(): self
    {
        return new self(
            'The Case Order API could not be reached. The request was not completed, so today\'s '
                . 'allowance has not been used.',
            false,
        );
    }

    /** The provider answered, and the answer was a refusal. The request happened. */
    public static function refused(int $status): self
    {
        return new self(
            'The Case Order API refused the request with HTTP ' . $status . '.',
            true,
            $status,
        );
    }

    /**
     * The provider says we are asking too often.
     *
     * Distinct from {@see refused()} because this one carries an instruction rather than only a
     * complaint, and because it is the single limit a manual press may not talk its way past. The
     * request was made, so the attempt counts; nothing retries it automatically.
     */
    public static function rateLimited(int $status, ?int $retryAfterSeconds): self
    {
        $wait = $retryAfterSeconds === null
            ? 'It did not say when another request would be accepted.'
            : 'It asked for another request no sooner than ' . $retryAfterSeconds . ' second(s).';

        return new self(
            'The Case Order API refused the request as too frequent (HTTP ' . $status . '). ' . $wait,
            true,
            $status,
            $retryAfterSeconds,
        );
    }

    public static function tooLarge(int $limitBytes): self
    {
        return new self(
            'The Case Order API response exceeded the ' . number_format($limitBytes / 1048576, 0)
                . ' MB limit and was abandoned.',
            true,
        );
    }

    public static function empty(): self
    {
        return new self('The Case Order API returned an empty response.', true);
    }

    public static function malformed(): self
    {
        return new self('The Case Order API response was not valid JSON.', true);
    }
}
