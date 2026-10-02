<?php

declare(strict_types=1);

namespace App\Order58\Client\Orders;

use RuntimeException;

/**
 * The Orders API did not give us something usable.
 *
 * The message is written to be shown to an administrator, so it names what went wrong and never carries
 * a credential, a header or a raw body — a provider's error page is written by somebody else and this
 * one would be rendered on an admin screen.
 */
final class OrderDataFailed extends RuntimeException
{
    public static function notConfigured(string $problems): self
    {
        return new self('The Orders API is not configured on this server: ' . $problems);
    }

    public static function refused(int $status): self
    {
        return new self(match (true) {
            $status === 401 || $status === 403 => 'The Orders API rejected this server\'s credentials (HTTP '
                . $status . '). The token may need rotating.',
            $status === 429 => 'The Orders API is rate limiting this server (HTTP 429). Try again shortly.',
            $status >= 500 => 'The Orders API is unavailable (HTTP ' . $status . '). This is a fault at '
                . 'their end; nothing was saved.',
            default => 'The Orders API refused the request (HTTP ' . $status . ').',
        });
    }

    public static function unreachable(): self
    {
        return new self('The Orders API could not be reached, or did not answer in time. Nothing was saved.');
    }

    public static function tooLarge(int $limitBytes): self
    {
        return new self(sprintf(
            'The response is larger than this server will read in one request (%d MB). Narrow the date '
            . 'range and sync again — nothing was saved.',
            (int) ($limitBytes / 1048576),
        ));
    }

    public static function malformed(string $why): self
    {
        return new self('The Orders API returned something this application could not read: ' . $why);
    }
}
