<?php

declare(strict_types=1);

namespace App\Order58\Web\TestRecordingChannels;

use RuntimeException;

/**
 * Thrown when fixture recordings are reached for outside development or test.
 *
 * It should be unreachable: every entry point consults {@see FixtureAvailability} before asking for a
 * fixture at all. It exists because "unreachable" is a property of today's callers, and the thing being
 * prevented — a production page serving generated test tone as a customer's recording — is bad enough
 * that it deserves a refusal that does not depend on every future caller remembering the rule.
 */
final class FixturesNotPermitted extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Fixture recordings are available in development and test only. This environment must use the '
            . 'live recording API.',
        );
    }
}
