<?php

declare(strict_types=1);

namespace App\Order58\Web\TestRecordingChannels;

use App\Environment;

/**
 * The one gate deciding whether local fixture recordings may be used instead of the live API.
 *
 * ## It cannot activate in production, and it cannot activate silently
 *
 * Two independent conditions, and **both** must hold:
 *
 *  1. `APP_ENV` is not `prod`. Checked positively against the two environments that may use fixtures,
 *     never as `!== 'prod'` — a typo'd or unrecognised value then falls through to the live API rather
 *     than to fixtures, which is the safe direction to fail in.
 *  2. The operator asked for it **on this specific request**, with `?source=fixture` in the URL.
 *
 * The second condition is what makes it impossible to activate silently. There is no default, no
 * sticky setting, no environment variable that turns it on for every request — somebody has to type it,
 * every time, and the page states in plain words which source answered.
 *
 * ## Why fixtures exist at all
 *
 * The external recording API is gated by an IP allowlist and this machine is not on it, so every live
 * request from here fails with a 403 before any of the page's own logic runs. Fixtures make the parsing,
 * WAV validation, streaming and download paths testable now, without pretending the external API
 * succeeded and without touching the allowlist.
 */
final readonly class FixtureAvailability
{
    /** The query parameter an operator must type to opt in, on every request. */
    public const QUERY_PARAMETER = 'source';

    public const FIXTURE = 'fixture';
    public const LIVE = 'live';

    /**
     * Whether fixtures may be offered at all in this environment.
     *
     * Positive allow-list, not `!== prod`: an `APP_ENV` this build does not recognise answers **false**
     * and gets the live API. Failing towards the real provider is the right direction — the worst
     * outcome is a 403 an operator can read, rather than a production page quietly serving test audio.
     */
    public static function isPermitted(): bool
    {
        $environment = Environment::appEnv();

        return $environment === Environment::DEV || $environment === Environment::TEST;
    }

    /**
     * Whether *this* request should be answered from fixtures.
     *
     * @param mixed $requestedSource the raw `source` query value, in whatever shape it arrived
     */
    public static function isRequested(mixed $requestedSource): bool
    {
        return self::isPermitted() && $requestedSource === self::FIXTURE;
    }

    /** What the page should say about where its answer came from. Never left implicit. */
    public static function describe(bool $usingFixtures): string
    {
        return $usingFixtures
            ? 'LOCAL FIXTURE — no external request was made. These bytes come from a test file in this '
                . 'repository, not from the recording API.'
            : 'LIVE API — a real request to the external recording service.';
    }
}
