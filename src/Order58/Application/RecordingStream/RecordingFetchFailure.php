<?php

declare(strict_types=1);

namespace App\Order58\Application\RecordingStream;

/**
 * Why a recording could not be fetched, in terms a page can act on.
 *
 * Kept apart from HTTP status codes because two of them never reach the provider at all — a link this
 * server refuses to fetch, and a server that is already streaming as many recordings as it allows.
 */
enum RecordingFetchFailure: string
{
    /** The link is not one {@see \App\Integration\Order58Recording\RecordingUrlPolicy} accepts. No request was made. */
    case Refused = 'refused';

    /** Every streaming slot is in use. No request was made. */
    case Busy = 'busy';

    case Unreachable = 'unreachable';

    case TimedOut = 'timed-out';

    /** 404 or 410: no recording at that link — not uploaded yet, or no longer kept. */
    case NotFound = 'not-found';

    /** 401 or 403: refused. From a server outside the client's IP allowlist, this is every request. */
    case Forbidden = 'forbidden';

    case RateLimited = 'rate-limited';

    /** Any other answer that is not a 2xx, including a redirect, which is never followed. */
    case UpstreamError = 'upstream-error';

    /** A 2xx whose body is not a WAV file — an error page answered with 200, for instance. */
    case NotAudio = 'not-audio';

    case TooLarge = 'too-large';

    /** The connection ended before the provider's own Content-Length was reached. */
    case Truncated = 'truncated';
}
