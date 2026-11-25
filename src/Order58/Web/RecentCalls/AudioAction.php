<?php

declare(strict_types=1);

namespace App\Order58\Web\RecentCalls;

use App\Integration\Order58Recording\ChannelApiProbe;
use App\Integration\Order58Recording\ChannelRecordingRequest;
use App\Integration\Order58Recording\FixtureAvailability;
use App\Integration\Order58Recording\FixtureRecordingSource;
use App\Integration\Order58Recording\RecordingChannel;
use App\Integration\Order58Recording\UnconfirmedChannelMapping;
use App\Order58\Application\RecordingStream\RecordingFetch;
use App\Order58\Application\RecordingStream\RecordingFetchFailure;
use App\Order58\Application\RecordingStream\RecordingStreamer;
use App\Order58\Web\RecordingAudio\RecordingResponder;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Yiisoft\Session\SessionInterface;

use function filesize;
use function is_scalar;
use function trim;

/**
 * `GET /admin/order58/recent-calls/audio` — one channel of one call, streamed when Play or Download is
 * pressed, and kept nowhere.
 *
 * ## Not a URL proxy
 *
 * The browser sends a store, a session id, a date and a channel; never an address. The store must be
 * one of {@see RecentCallsSettings}'s, the rest is validated by {@see ChannelRequestMapping}'s own request
 * object, and the address is built by the shared mapping — the single place the channel suffix is put on
 * the path. The fetch itself re-checks the address against the recording host's policy and refuses
 * redirects.
 *
 * ## The store page's playback, reused
 *
 * {@see RecordingStreamer} and {@see RecordingResponder} are exactly what plays Case Order recordings on
 * Manage Orders: the same four playback slots shared with everyone listening, the same timeouts and size
 * cap, a RIFF/WAVE check before anything is called audio, a memory-bounded spool per request, byte-range
 * answers for seeking, and plain-text refusals rather than an HTML page saved as `.wav`.
 */
final readonly class AudioAction
{
    public function __construct(
        private ChannelApiProbe $probe,
        private RecordingStreamer $streamer,
        private RecordingResponder $responder,
        private FixtureRecordingSource $fixtures,
        private SessionInterface $session,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();

        $accountId = RecentCallsSettings::find($params['store'] ?? null);
        $channel = RecordingChannel::fromStorage($this->text($params['channel'] ?? null));

        if ($accountId === null || $channel === null) {
            return $this->responder->problem(400, 'Invalid recording request: unknown store or channel.');
        }

        $session = $this->text($params['session'] ?? null);
        $date = $this->text($params['date'] ?? null);
        $company = RecentCallsSettings::company($accountId);

        // The same rules the channel test page and the call importer enforce. Merchant id is not sent to
        // the provider; the request object validates it, so it is given the store the call belongs to.
        if (ChannelRecordingRequest::validate($session, (string) $accountId, $date, $company, $session) !== null) {
            return $this->responder->problem(
                400,
                'Invalid recording request: a session id (digits) and a real calendar date are required.',
            );
        }

        $recording = new ChannelRecordingRequest($session, (string) $accountId, $date, $company, $session);
        $download = $this->text($params['download'] ?? null) === '1';
        $fileName = $channel->fileNameFor($session);

        // Released before the provider is contacted: the fetch can take seconds, and every other request
        // from the same person would otherwise wait on the session file for all of them.
        $this->session->close();

        if (FixtureAvailability::isRequested($params[FixtureAvailability::QUERY_PARAMETER] ?? null)) {
            return $this->fromFixture($recording, $channel, $request, $download, $fileName);
        }

        try {
            $url = $this->probe->urlFor($recording, $channel);
        } catch (UnconfirmedChannelMapping) {
            return $this->responder->problem(501, 'This recording channel cannot be requested yet.');
        }

        $fetch = $this->streamer->fetch($url);

        if (!$fetch->isOk()) {
            return $this->failed($accountId, $session, $channel, $fetch);
        }

        return $this->responder->respond($fetch->body, $fetch->size, $request, $download, $fileName);
    }

    /** Development and test only, and only when this request asked for it — see FixtureAvailability. */
    private function fromFixture(
        ChannelRecordingRequest $recording,
        RecordingChannel $channel,
        ServerRequestInterface $request,
        bool $download,
        string $fileName,
    ): ResponseInterface {
        $path = $this->fixtures->pathFor($recording->recordingId, $channel);
        $size = $path === null ? false : filesize($path);

        if ($path === null || $size === false) {
            return $this->responder->problem(404, 'No local sample recording exists for this call and channel.');
        }

        return $this->responder->respond(Utils::streamFor(Utils::tryFopen($path, 'rb')), $size, $request, $download, $fileName);
    }

    private function failed(int $accountId, string $session, RecordingChannel $channel, RecordingFetch $fetch): ResponseInterface
    {
        $failure = $fetch->failure ?? RecordingFetchFailure::UpstreamError;

        // Ids, the reason and the provider's status. Never the URL.
        $this->logger->warning('A recent-call recording could not be streamed.', [
            'reason' => 'order58_recent_call_recording_failed',
            'failure' => $failure->value,
            'upstream_status' => $fetch->upstreamStatus,
            'store_source_id' => $accountId,
            'call_session_id' => $session,
            'channel' => $channel->value,
        ]);

        return match ($failure) {
            RecordingFetchFailure::NotFound => $this->responder->problem(
                404,
                'Order58 has no ' . $channel->label() . ' recording for this call. It may not be ready yet, '
                . 'or this store may not have separated channels.',
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
                'This recording\'s address is not one this server will fetch.',
            ),
            RecordingFetchFailure::Truncated, RecordingFetchFailure::UpstreamError => $this->responder->problem(
                502,
                'The recording service did not return this recording. Try again shortly.',
            ),
        };
    }

    private function text(mixed $raw): string
    {
        return is_scalar($raw) ? trim((string) $raw) : '';
    }
}
