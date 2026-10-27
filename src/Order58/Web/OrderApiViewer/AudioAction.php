<?php

declare(strict_types=1);

namespace App\Order58\Web\OrderApiViewer;

use App\Order58\Application\RecordingStream\RecordingFetchFailure;
use App\Order58\Application\RecordingStream\RecordingStreamer;
use App\Order58\Web\RecordingAudio\RecordingResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function is_scalar;
use function sprintf;
use function trim;

/**
 * Streams one recording to the browser
 * (GET /admin/order-api-viewer/audio?session=…&time=…).
 *
 * ## Not a URL proxy
 *
 * It takes a digits-only session id and a calendar date, and builds the address from constants —
 * see {@see RecordingRef}. There is no parameter that can name a host, a scheme or a path, so this
 * cannot be pointed at an internal service however the query string is bent. Redirects are **not
 * followed**, which closes the one remaining way an external answer could move the request elsewhere.
 *
 * ## The fetching is shared
 *
 * This page proved the approach on the production server, and the order pages now play Case Order
 * recordings the same way: {@see RecordingStreamer} makes the request, confirms the body is RIFF/WAVE
 * before anything calls it audio, and spools it per request; {@see RecordingResponder} answers whole
 * files and byte ranges. What stays here is what is particular to this page — the session-and-date
 * reference and the wording of its failures, which name the session and date that were asked for.
 *
 * ## Why the Content-Type is rewritten
 *
 * The provider answers `application/octet-stream`. Browsers will not reliably play that in an `<audio>`
 * element, which is the whole reason this endpoint exists rather than a plain link. The header is only
 * set to `audio/wav` **after** the first bytes are confirmed to be RIFF/WAVE.
 *
 * ## Range, and the honest limitation
 *
 * Measured on 27 October 2026: the provider ignores `Range` — it answers `200` with the whole file and
 * sends no `Accept-Ranges`. Seeking is offered from the request's own spool, which costs an upstream
 * fetch per seek the browser has not already buffered, but needs no disk that outlives the request.
 *
 * ## Nothing is kept
 *
 * No file outlives the request, no cache entry, no database row, and the full URL is never logged.
 */
final readonly class AudioAction
{
    public function __construct(
        private RecordingStreamer $streamer,
        private RecordingResponder $responder,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();

        $ref = RecordingRef::fromInput(
            $this->text($params['session'] ?? null),
            $this->text($params['time'] ?? null),
        );

        if ($ref === null) {
            return $this->responder->problem(400, 'Invalid recording reference. A session id (digits) and a real '
                . 'calendar date are both required.');
        }

        $fetch = $this->streamer->fetch($ref->url());

        if ($fetch->isOk()) {
            return $this->responder->respond(
                $fetch->body,
                $fetch->size,
                $request,
                $this->text($params['download'] ?? null) === '1',
                $ref->downloadFileName(),
            );
        }

        $status = $fetch->upstreamStatus ?? 0;

        return match ($fetch->failure) {
            RecordingFetchFailure::Unreachable, RecordingFetchFailure::TimedOut => $this->responder->problem(
                502,
                sprintf(
                    "The recording service could not be reached for session %s.\n\n%s",
                    $ref->sessionId,
                    $fetch->detail,
                ),
            ),
            RecordingFetchFailure::NotAudio => $this->responder->problem(502, sprintf(
                "The recording service answered HTTP %d for session %s, but the body is not a WAV file.\n\n%s",
                $status,
                $ref->sessionId,
                $fetch->detail,
            )),
            RecordingFetchFailure::Busy => $this->responder->problem(
                503,
                'Too many recordings are being played right now. Try again in a moment.',
                $fetch->retryAfterSeconds ?? 3,
            ),
            RecordingFetchFailure::TooLarge => $this->responder->problem(
                502,
                sprintf('The recording for session %s is larger than this server will stream.', $ref->sessionId),
            ),
            default => $this->responder->problem(502, sprintf(
                "The recording service answered HTTP %d for session %s on %s.\n\n%s",
                $status,
                $ref->sessionId,
                $ref->time,
                $status === 404
                    ? 'No recording exists at that address. The date must match the recording\'s own date.'
                    : ($status === 403
                        ? 'Access was refused. This server\'s outbound IP may not be whitelisted.'
                        : 'The service did not return a recording.'),
            )),
        };
    }

    private function text(mixed $raw): string
    {
        return is_scalar($raw) ? trim((string) $raw) : '';
    }
}
