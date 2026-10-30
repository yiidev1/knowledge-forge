<?php

declare(strict_types=1);

namespace App\Order58\Web\RecordingAudio;

use App\Order58\Application\RecordingStream\RecordingFetch;
use App\Order58\Application\RecordingStream\RecordingFetchFailure;
use App\Order58\Application\RecordingStream\RecordingStreamer;
use App\Order58\Domain\CallRecordings\CallRecordingRepositoryInterface;
use App\Order58\Domain\CallRecordings\RecordingLocation;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Session\SessionInterface;

/**
 * Plays or downloads one Case Order call recording
 * (GET …/store/{sourceId}/order/{sourceOrderId}/recording/{recordingId}[?download=1]).
 *
 * ## The order's own address, and nothing the browser could bend
 *
 * The browser names a recording by three numbers the page already prints — store, order, recording —
 * and the lookup requires all three together, so an id read off one order cannot be replayed against
 * another order or another store; any mismatch is the same 404 as an id that does not exist. The URL is
 * read from the database on the server and never crosses to the browser: it carries the customer's
 * phone number, and it is not an access rule anyway. Who may ask at all is the route group's gate —
 * administrators and operation users on the Order Testing routes, agents on their own practice routes,
 * where the middleware additionally proves the store and the recording are within their practice scope.
 *
 * ## Fetched on demand, kept nowhere
 *
 * The provider is asked when somebody presses Play or Download, from this server's allowlisted address,
 * and the answer is streamed back through a request-scoped spool — see {@see RecordingStreamer}. No WAV
 * is written to disk, cached, or stored in the database.
 *
 * Failures are plain text with a status a person can act on, and are logged by id — never with the URL.
 */
final readonly class Action
{
    public function __construct(
        private CallRecordingRepositoryInterface $recordings,
        private RecordingStreamer $streamer,
        private RecordingResponder $responder,
        private LoggerInterface $logger,
        private SessionInterface $session,
    ) {}

    public function __invoke(
        #[RouteArgument]
        int $sourceId,
        #[RouteArgument]
        int $sourceOrderId,
        #[RouteArgument]
        int $recordingId,
        ServerRequestInterface $request,
    ): ResponseInterface {
        $location = $this->recordings->locate($sourceId, $sourceOrderId, $recordingId);

        if ($location === null) {
            return $this->responder->problem(404, 'No such recording for this order.');
        }

        /*
         * The session lock is released here, before anything talks to the provider.
         *
         * PHP's `files` session handler holds an exclusive lock on the session file for the whole
         * request, and `SessionMiddleware` only closes it after this action returns. Everything below
         * is slow and needs no session: the gate waits up to 2 s for a slot, the provider takes
         * 0.7–3 s to its first byte, and the whole WAV is spooled before a byte is sent. For that
         * entire time every other request from the same signed-in person — a sidebar click, another
         * recording — blocks in `session_start()`, because they share one session file.
         *
         * Authentication and the route group's access rules have already run by the time an action is
         * reached, and the lookup above used the database, not the session. Nothing after this line
         * reads or writes it: the failure path logs and returns a problem response, with no flash.
         *
         * The same release is already applied to every other slow action in this application — chat,
         * rule chat, agent chat, AI usage sync — for exactly this reason.
         */
        $this->session->close();

        // A duration probe: the page's "--:--" labels. Answered from the recording's SIZE, so the
        // provider transfers nothing. Placed before the fetch below because it must never reach it —
        // the whole point is not to download audio in order to print a number.
        if (($request->getQueryParams()['duration'] ?? null) === '1') {
            $measured = $this->streamer->measure($location->url);

            // 409, not 404 or 500: the recording exists and this server is well — only the shortcut
            // declined, and the page reads the real header instead, exactly as it did before. A
            // distinct status keeps that apart from a genuine failure in the access log.
            return $measured->isUsable()
                ? $this->responder->duration((float) $measured->seconds)
                : $this->responder->problem(409, 'Duration could not be determined from the recording size.');
        }

        $fetch = $this->streamer->fetch($location->url);

        if (!$fetch->isOk()) {
            return $this->failed($location, $fetch);
        }

        return $this->responder->respond(
            $fetch->body,
            $fetch->size,
            $request,
            ($request->getQueryParams()['download'] ?? null) === '1',
            $location->downloadFileName(),
        );
    }

    private function failed(RecordingLocation $location, RecordingFetch $fetch): ResponseInterface
    {
        $failure = $fetch->failure ?? RecordingFetchFailure::UpstreamError;

        // Ids, the reason and the provider's status. Never the URL: it holds a phone number.
        $this->logger->warning('A Case Order call recording could not be streamed.', [
            'reason' => 'order58_call_recording_stream_failed',
            'failure' => $failure->value,
            'upstream_status' => $fetch->upstreamStatus,
            'detail' => $fetch->detail,
            'recording_id' => $location->recordingId,
            'store_source_id' => $location->storeSourceId,
            'source_order_id' => $location->sourceOrderId,
            'call_session_id' => $location->callSessionId,
        ]);

        return match ($failure) {
            RecordingFetchFailure::NotFound => $this->responder->problem(
                404,
                'Order58 has no recording at this link. It may not be available yet, or it may no longer be kept.',
            ),
            RecordingFetchFailure::Busy => $this->responder->problem(
                503,
                'Too many recordings are being played right now. Try again in a moment.',
                $fetch->retryAfterSeconds ?? 3,
            ),
            RecordingFetchFailure::RateLimited => $this->responder->problem(
                503,
                'The recording service asked for fewer requests. Try again shortly.',
                $fetch->retryAfterSeconds ?? 30,
            ),
            RecordingFetchFailure::Forbidden => $this->responder->problem(
                502,
                $fetch->detail === 'ip-not-allowlisted'
                    ? 'The recording service refused this server: its IP address is not on the client\'s allowlist.'
                    : 'The recording service refused access to this recording.',
            ),
            RecordingFetchFailure::Unreachable, RecordingFetchFailure::TimedOut => $this->responder->problem(
                504,
                'The recording service could not be reached in time. Try again shortly.',
            ),
            RecordingFetchFailure::NotAudio => $this->responder->problem(
                502,
                'The recording service did not return an audio file for this recording.',
            ),
            RecordingFetchFailure::TooLarge => $this->responder->problem(
                502,
                'This recording is larger than this server will stream.',
            ),
            RecordingFetchFailure::Refused => $this->responder->problem(
                502,
                'This recording\'s link is not one this server will fetch.',
            ),
            RecordingFetchFailure::Truncated, RecordingFetchFailure::UpstreamError => $this->responder->problem(
                502,
                'The recording service did not return this recording. Try again shortly.',
            ),
        };
    }
}
