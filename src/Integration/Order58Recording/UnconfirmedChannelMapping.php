<?php

declare(strict_types=1);

namespace App\Integration\Order58Recording;

use RuntimeException;

use function sprintf;

/**
 * Thrown when a caller or callee request cannot be built because the mapping is not resolved.
 *
 * Refusing is the point. The failure this protects against is not a 404 — it is a request that succeeds
 * against the wrong reading and returns the **mixed** recording while the page reports it as the caller
 * channel. That reads as success, gets believed, and is only caught later by somebody listening to a
 * training file and hearing two voices where they expected one.
 *
 * So an unrecognised {@see ChannelRequestMapping::CANDIDATE} produces nothing at all rather than a
 * plausible guess.
 */
final class UnconfirmedChannelMapping extends RuntimeException
{
    public function __construct(RecordingChannel $channel)
    {
        parent::__construct(sprintf(
            'The request format for the %s channel is not configured. Set ChannelRequestMapping::CANDIDATE '
            . 'to a recognised reading once the client has confirmed how the external API expects a '
            . 'separated channel to be requested.',
            $channel->label(),
        ));
    }
}
