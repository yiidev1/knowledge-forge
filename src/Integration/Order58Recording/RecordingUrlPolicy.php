<?php

declare(strict_types=1);

namespace App\Integration\Order58Recording;

use function in_array;
use function is_array;
use function is_string;
use function parse_url;
use function preg_match;
use function rtrim;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;

use const PHP_URL_HOST;
use const PHP_URL_PATH;

/**
 * Which recording links this application will store, and later fetch.
 *
 * Case Order recordings arrive as full URLs inside the provider's payload, and the audio endpoint later
 * fetches whatever URL the database holds. That makes this the server-side request forgery boundary: a
 * payload is not an attacker, but it is not this application's own data either, and a link that pointed
 * somewhere else must never become a request this server makes.
 *
 * So a URL is accepted only when every part of it is the expected one — `https`, the recording host
 * itself, no port but the default, no user info, and the recording fetch path followed by a session
 * id. It is checked twice: once before it is stored, and again before it is fetched, so a row written
 * by any other route still cannot point the fetcher anywhere else. The fetcher also refuses redirects,
 * which is the one way an accepted address could still lead somewhere unaccepted.
 *
 * The host is code rather than a setting on purpose. Adding a host is a decision about where this server
 * may send requests, and that belongs in a reviewed change, not in an environment file.
 */
final class RecordingUrlPolicy
{
    /**
     * The only host a recording may be fetched from, and the path a recording lives under.
     *
     * Both are read from {@see ChannelRequestMapping::BASE_URL}, the one place this application states
     * the recording API's address — a second spelling of it here would be a second thing to keep in
     * step, and the channel tool's isolation test forbids one.
     */
    private const FETCH_SEGMENT = '/fetch/';

    /** The column holds 2048 characters; a longer link is refused rather than truncated into another. */
    public const MAX_LENGTH = 2048;

    public static function accepts(string $url): bool
    {
        $rest = self::sessionPart($url);

        // The rest of the path is the session id and nothing else: no further segments, no `..`.
        return $rest !== null && preg_match('/\A\d{1,20}\z/', $rest) === 1;
    }

    /**
     * {@see accepts()}, or the same address for one separated channel — `/fetch/{session}-caller`.
     *
     * Every other rule is identical: the scheme, the host, the port, no user info, no fragment, one
     * path segment. The only difference is that the segment may carry a channel suffix, and only the
     * exact suffix {@see RecordingChannel::requestSegmentFor()} produces — so the convention is still
     * stated in one place, and nothing else can ride in on the path.
     *
     * Used where this server fetches a recording on demand, never where a link is accepted for storage:
     * the provider's Case Order links are plain session ids, and {@see accepts()} still guards those.
     */
    public static function acceptsForFetch(string $url): bool
    {
        if (self::accepts($url)) {
            return true;
        }

        $rest = self::sessionPart($url);

        if ($rest === null || preg_match('/\A(\d{1,20})-[a-z]{1,10}\z/', $rest, $matches) !== 1) {
            return false;
        }

        foreach (RecordingChannel::all() as $channel) {
            if ($channel !== RecordingChannel::Mixed && $channel->requestSegmentFor($matches[1]) === $rest) {
                return true;
            }
        }

        return false;
    }

    /**
     * Everything after `/fetch/` for an address that passes every rule except the session's own shape,
     * or null when any other rule fails.
     */
    private static function sessionPart(string $url): ?string
    {
        if ($url === '' || strlen($url) > self::MAX_LENGTH) {
            return null;
        }

        // Control characters and whitespace have no place in a URL the provider generated, and they are
        // exactly what makes one parser read a URL differently from another.
        if (preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);

        if (!is_array($parts)) {
            return null;
        }

        if (strtolower($parts['scheme'] ?? '') !== 'https') {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return null;
        }

        if (isset($parts['port']) && $parts['port'] !== 443) {
            return null;
        }

        $host = $parts['host'] ?? null;

        if (!is_string($host) || !in_array(strtolower($host), self::allowedHosts(), true)) {
            return null;
        }

        $path = $parts['path'] ?? '';
        $prefix = self::pathPrefix();

        if (!str_starts_with($path, $prefix)) {
            return null;
        }

        return substr($path, strlen($prefix));
    }

    /** @return list<string> */
    public static function allowedHosts(): array
    {
        $host = parse_url(ChannelRequestMapping::BASE_URL, PHP_URL_HOST);

        return is_string($host) ? [strtolower($host)] : [];
    }

    /** `/…/recording/fetch/` — what every recording's path starts with. */
    public static function pathPrefix(): string
    {
        $path = parse_url(ChannelRequestMapping::BASE_URL, PHP_URL_PATH);

        return (is_string($path) ? rtrim($path, '/') : '') . self::FETCH_SEGMENT;
    }

    /**
     * The session id the path names, for a URL {@see accepts()} has already passed.
     *
     * Used only to name a fixture file and a download; the URL itself is never rebuilt from it.
     */
    public static function sessionIdOf(string $url): ?string
    {
        if (!self::accepts($url)) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) ? substr($path, strlen(self::pathPrefix())) : null;
    }
}
