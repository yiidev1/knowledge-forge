<?php

declare(strict_types=1);

namespace App\Order58\Client\Items;

use RuntimeException;

use function sprintf;

/**
 * The Order Items API did not give us something usable.
 *
 * Every message is written to be shown to an administrator and stored in `items_error`, so it names
 * what went wrong and **never** carries a credential, a header or a raw body. A provider's error page
 * is written by somebody else; repeating it on an admin screen is how a token ends up in a screenshot.
 *
 * Reaching any of these means the existing item snapshot is left exactly as it was. "Could not fetch"
 * and "there are no items" are different answers, and only the API saying `OrderItems: []` means the
 * second one.
 */
final class OrderItemDataFailed extends RuntimeException
{
    public static function notConfigured(string $problems): self
    {
        return new self('The Order Items API is not configured on this server: ' . $problems);
    }

    public static function disabled(): self
    {
        return new self(
            'Original item synchronisation is switched off on this server '
            . '(ORDER58_ITEM_SYNC_ENABLED). Nothing was requested.',
        );
    }

    public static function refused(int $status): self
    {
        return new self(match (true) {
            $status === 401 || $status === 403 => 'The Order Items API rejected this server\'s credentials '
                . '(HTTP ' . $status . '). The token, or the authentication scheme, may be wrong.',
            $status === 404 => 'The Order Items API does not know this order (HTTP 404).',
            $status === 429 => 'The Order Items API is rate limiting this server (HTTP 429). Try again shortly.',
            $status >= 500 => 'The Order Items API is unavailable (HTTP ' . $status . '). This is a fault '
                . 'at their end; the items already stored were kept.',
            default => 'The Order Items API refused the request (HTTP ' . $status . ').',
        });
    }

    public static function unreachable(): self
    {
        return new self(
            'The Order Items API could not be reached, or did not answer in time. '
            . 'The items already stored were kept.',
        );
    }

    public static function tooLarge(int $limitBytes): self
    {
        return new self(sprintf(
            'The item response is larger than this server will read (%d MB). The items already stored '
            . 'were kept.',
            (int) ($limitBytes / 1048576),
        ));
    }

    public static function malformed(string $why): self
    {
        return new self(
            'The Order Items API returned something this application could not read: ' . $why
            . ' The items already stored were kept.',
        );
    }
}
