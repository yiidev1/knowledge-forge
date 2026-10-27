<?php

declare(strict_types=1);

namespace App\Order58\Web\OrderApiViewer;

use DateTimeImmutable;

use function http_build_query;
use function is_array;
use function is_string;
use function parse_str;
use function parse_url;
use function preg_match;
use function rawurlencode;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * One playable recording, named by **two scalars rather than by a URL**.
 *
 * ## Why the browser never sends a URL
 *
 * An audio endpoint that fetches whatever address the page hands it is a server-side request forgery
 * proxy wearing a Play button: the browser is not a trusted party, and "only our page calls it" is not
 * an access rule. Allow-listing the host afterwards helps, but it is a filter on attacker-controlled
 * input, and filters are where the interesting bugs live — a redirect, a userinfo `@`, a second `?`.
 *
 * So no URL crosses the boundary in either direction. The browser sends a **session id** and a **date**,
 * both of which are checked against a pattern that admits nothing but digits and a calendar date, and
 * the address is assembled here from constants. There is no input that could name another host, because
 * the host is not an input.
 *
 * ## Which parts of the address actually matter, measured
 *
 * Checked against the live provider on 27 October 2026, on a real recording:
 *
 * - **`time` is load-bearing.** The recording's own date returns the file; today's date returns 404.
 *   It is therefore carried, not guessed.
 * - **`name` is required but cosmetic.** Replacing the provider's
 *   `15594361-14014514904-21403649` with the bare session id returned a byte-identical file; omitting
 *   it entirely returned 400. So it is sent, and it is built from the session id rather than carried —
 *   one less value to trust from the page.
 * - **`company` is required** and is a deployment constant.
 *
 * That measurement is the reason this class can exist at all: if `name` had been load-bearing, its
 * opaque middle number could not have been reconstructed and a URL would have had to be trusted.
 */
final readonly class RecordingRef
{
    /** The only host this feature will ever fetch from. Not a filter — a constant. */
    public const HOST = 'order58.xrainbow.com';

    /** The only path. Appended to, never replaced by, anything from the request. */
    public const PATH_PREFIX = '/api/external/recording/fetch/';

    private const COMPANY = 'SWCC';

    public function __construct(
        public string $sessionId,
        public string $time,
    ) {}

    /**
     * Read a reference out of request input.
     *
     * Returns null rather than throwing: a malformed reference is an ordinary thing a stale page or a
     * poking browser produces, and the endpoint answers it with a refusal rather than a stack trace.
     */
    public static function fromInput(string $sessionId, string $time): ?self
    {
        if (preg_match('/\A\d{1,20}\z/', $sessionId) !== 1) {
            return null;
        }

        if (!self::isCalendarDate($time)) {
            return null;
        }

        return new self($sessionId, $time);
    }

    /**
     * Pull the two scalars out of a provider URL, for the page to render a Play button from.
     *
     * This runs on the **server**, against the payload the API just returned — the one place a provider
     * URL may be read. What crosses to the browser is the result of this, never the URL itself.
     */
    public static function fromProviderUrl(string $url, ?string $sessionIdHint): ?self
    {
        $parts = parse_url($url);

        if (!is_array($parts)) {
            return null;
        }

        // Host and path are verified even here. A payload is not an attacker, but it is also not this
        // application's own data, and a link that pointed elsewhere should not become a Play button.
        if (($parts['host'] ?? null) !== self::HOST) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '');

        if (!str_starts_with($path, self::PATH_PREFIX)) {
            return null;
        }

        $sessionId = substr($path, strlen(self::PATH_PREFIX));
        parse_str((string) ($parts['query'] ?? ''), $query);

        $time = $query['time'] ?? null;

        return self::fromInput(
            $sessionId !== '' ? $sessionId : (string) $sessionIdHint,
            is_string($time) ? $time : '',
        );
    }

    /**
     * The address to fetch, assembled from constants plus two validated scalars.
     *
     * `name` is built rather than carried — see the class docblock for the measurement that makes that
     * safe. It is the session id with no extension: the provider appends one, and a `.wav` here would
     * produce `.wav.wav` on the operator's disk.
     */
    public function url(): string
    {
        return sprintf(
            'https://%s%s%s?%s',
            self::HOST,
            self::PATH_PREFIX,
            rawurlencode($this->sessionId),
            http_build_query([
                'time' => $this->time,
                'company' => self::COMPANY,
                'name' => $this->sessionId,
            ]),
        );
    }

    /** The filename offered to the browser on a download. Digits only, so it cannot carry a separator. */
    public function downloadFileName(): string
    {
        return $this->sessionId . '.wav';
    }

    /** A real calendar date, not merely something shaped like one: `2026-02-31` is refused. */
    private static function isCalendarDate(string $value): bool
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
            return false;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }
}
