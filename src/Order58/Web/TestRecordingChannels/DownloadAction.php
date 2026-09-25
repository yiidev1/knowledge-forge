<?php

declare(strict_types=1);

namespace App\Order58\Web\TestRecordingChannels;

use App\Integration\Order58Recording\ChannelApiProbe;
use App\Integration\Order58Recording\ChannelDiagnosis;
use App\Integration\Order58Recording\ChannelRecordingRequest;
use App\Integration\Order58Recording\FixtureAvailability;
use App\Integration\Order58Recording\FixtureRecordingSource;
use App\Integration\Order58Recording\RecordingChannel;
use App\Integration\Order58Recording\UnconfirmedChannelMapping;
use App\Integration\Order58Recording\WavSignature;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Throwable;

use function filesize;
use function is_scalar;
use function preg_replace;
use function sprintf;
use function strlen;
use function substr;
use function trim;

/**
 * Streams one channel recording to the browser
 * (GET /admin/order58/test-recording-channels/download).
 *
 * The companion to the diagnostic page, and deliberately a separate route: the test button stays a test
 * that reports what came back, and only this endpoint ever hands the browser bytes.
 *
 * It re-runs the same request the page ran, through the same URL builder and the same validation, so it
 * cannot be reached with input the form would have rejected. Nothing is persisted on the way through:
 * the upstream body is passed to the response as a stream, so a multi-megabyte recording is never
 * buffered whole, never written to disk, and never reaches the database. There is no temporary file to
 * clean up because there is no temporary file.
 *
 * ## Refusals matter as much as the happy path
 *
 * A non-2xx upstream, or a 2xx whose body is not a WAV, produces a plain-text diagnostic rather than a
 * download. A page of HTML saved as `22342359-caller.wav` is exactly the failure this endpoint must not
 * produce — it looks like a working download and plays nothing.
 *
 * ## Inline or attachment
 *
 * `?disposition=inline` is what the page's Play button uses, so `<audio>` can read it from a same-origin
 * URL without the external host or any credential reaching the browser. Anything else is an attachment.
 */
final readonly class DownloadAction
{
    /** Enough of an upstream error body to diagnose it, bounded so a runaway body cannot be echoed whole. */
    private const MAX_ERROR_SNIPPET_BYTES = 4096;

    /** Enough to check a RIFF/WAVE header before deciding whether to hand anything over. */
    private const HEADER_PEEK_BYTES = 64;

    public function __construct(
        private ChannelApiProbe $probe,
        private FixtureRecordingSource $fixtures,
        private ResponseFactoryInterface $responseFactory,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();

        // No defaults here, unlike the form: the link always carries every value, and a missing one should
        // be reported rather than silently filled in with something the operator did not choose.
        $recordingId = $this->text($params['recording_id'] ?? null);
        $merchantId = $this->text($params['merchant_id'] ?? null);
        $time = $this->text($params['time'] ?? null);
        $company = $this->text($params['company'] ?? null);
        $name = $this->text($params['name'] ?? null);

        $channel = RecordingChannel::fromStorage($this->text($params['channel'] ?? null));

        if ($channel === null) {
            return $this->diagnostic(400, "Invalid download request — unknown channel.\n\nExpected one of: mixed, caller, callee.");
        }

        $invalid = ChannelRecordingRequest::validate($recordingId, $merchantId, $time, $company, $name);

        if ($invalid !== null) {
            return $this->diagnostic(400, "Invalid download request — nothing was sent to the API.\n\n" . $invalid);
        }

        $channelRequest = new ChannelRecordingRequest($recordingId, $merchantId, $time, $company, $name);
        $inline = $this->text($params['disposition'] ?? null) === 'inline';

        return FixtureAvailability::isRequested($params[FixtureAvailability::QUERY_PARAMETER] ?? null)
            ? $this->fromFixture($channelRequest, $channel, $inline)
            : $this->fromLive($channelRequest, $channel, $inline);
    }

    private function fromLive(
        ChannelRecordingRequest $request,
        RecordingChannel $channel,
        bool $inline,
    ): ResponseInterface {
        try {
            $url = $this->probe->urlFor($request, $channel);
        } catch (UnconfirmedChannelMapping $e) {
            return $this->diagnostic(501, sprintf(
                "No request was made.\n\n%s\n\nThe %s channel's request format has not been confirmed by the "
                    . "client, so this tool refuses to guess. A guessed URL that answers 200 with the MIXED "
                    . "recording would be reported as the %s channel, which reads as success.",
                $e->getMessage(),
                $channel->label(),
                $channel->label(),
            ));
        }

        try {
            $upstream = $this->probe->open($request, $channel);
        } catch (Throwable $e) {
            return $this->diagnostic(502, sprintf(
                "The recording request failed before any HTTP response arrived (DNS, TLS, connection or timeout).\n\n"
                    . "Request URL:\n%s\n\n%s: %s",
                $url,
                $e::class,
                $e->getMessage(),
            ));
        }

        $status = $upstream->getStatusCode();
        $contentType = $upstream->getHeaderLine('Content-Type');

        if ($status < 200 || $status >= 300) {
            $snippet = $this->snippet($upstream);
            $diagnosis = ChannelDiagnosis::fromResponse($status, $contentType, $snippet, strlen($snippet));

            return $this->diagnostic(502, sprintf(
                "The external API did not return a recording.\n\n%s\n%s\n\nRequest URL:\n%s\n\n"
                    . "Upstream HTTP status: %d %s\nUpstream Content-Type: %s\n\nUpstream response:\n%s",
                $diagnosis->headline,
                $diagnosis->advice,
                $url,
                $status,
                $upstream->getReasonPhrase(),
                $contentType === '' ? '(not sent)' : $contentType,
                $snippet,
            ));
        }

        // A 200 carrying HTML or JSON is an error dressed as success. The header is peeked rather than
        // trusting Content-Type, because the live endpoint answers `application/octet-stream` for real
        // audio — judging on the declared type would reject exactly the case this tool tests.
        $body = $upstream->getBody();
        $head = $body->read(self::HEADER_PEEK_BYTES);
        $wav = WavSignature::inspect($head, strlen($head) === 0 ? 0 : self::HEADER_PEEK_BYTES);

        if (!$wav->isWav) {
            return $this->diagnostic(502, sprintf(
                "The external API returned HTTP %d but the body is not a WAV file.\n\n%s\n\nRequest URL:\n%s\n\n"
                    . "Upstream Content-Type: %s\n\nFirst bytes:\n%s",
                $status,
                $wav->summary,
                $url,
                $contentType === '' ? '(not sent)' : $contentType,
                $this->clean($head),
            ));
        }

        // The peeked header is put back in front, so the browser receives the whole file.
        $stream = Utils::streamFor($head . $body->getContents());

        return $this->deliver($stream, $channel->fileNameFor($request->recordingId), $inline, $stream->getSize());
    }

    /**
     * Serve a local fixture. Reaching here required {@see FixtureAvailability::isRequested()}, which is
     * false in production and false unless this request explicitly asked for it.
     */
    private function fromFixture(
        ChannelRecordingRequest $request,
        RecordingChannel $channel,
        bool $inline,
    ): ResponseInterface {
        $path = $this->fixtures->pathFor($request->recordingId, $channel);

        if ($path === null) {
            return $this->diagnostic(404, sprintf(
                "No fixture exists for this recording id and channel.\n\nThe sample files cover recording %s only:\n"
                    . "  %s\n  %s\n  %s",
                FixtureRecordingSource::SAMPLE_RECORDING_ID,
                RecordingChannel::Mixed->fileNameFor(FixtureRecordingSource::SAMPLE_RECORDING_ID),
                RecordingChannel::Caller->fileNameFor(FixtureRecordingSource::SAMPLE_RECORDING_ID),
                RecordingChannel::Callee->fileNameFor(FixtureRecordingSource::SAMPLE_RECORDING_ID),
            ));
        }

        $size = filesize($path);

        return $this->deliver(
            Utils::streamFor(Utils::tryFopen($path, 'rb')),
            $channel->fileNameFor($request->recordingId),
            $inline,
            $size === false ? null : $size,
        );
    }

    /**
     * Hand the bytes over.
     *
     * The filename is built by {@see RecordingChannel::fileNameFor()} from an already-digits-validated
     * recording id, so it cannot contain a separator, a quote or a control character — it is
     * structurally safe in the header rather than sanitised afterwards. The upstream
     * `Content-Disposition` is deliberately ignored: it is a hint from an external server, and this tool
     * knows exactly what it asked for.
     */
    private function deliver(
        StreamInterface $stream,
        string $filename,
        bool $inline,
        ?int $size,
    ): ResponseInterface {
        $response = $this->responseFactory
            ->createResponse(200)
            // The real type, because this has been verified to start with a RIFF/WAVE header — unlike the
            // existing tool, which hands over unverified bytes and honestly calls them octet-stream.
            ->withHeader('Content-Type', 'audio/wav')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('Content-Disposition', sprintf(
                '%s; filename="%s"',
                $inline ? 'inline' : 'attachment',
                $filename,
            ))
            ->withBody($stream);

        return $size === null ? $response : $response->withHeader('Content-Length', (string) $size);
    }

    /**
     * An admin-only, plain-text explanation. Plain text on purpose: whatever went wrong, the browser must
     * not be told to save this as a file.
     */
    private function diagnostic(int $status, string $text): ResponseInterface
    {
        $response = $this->responseFactory
            ->createResponse($status)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('Content-Disposition', 'inline')
            ->withHeader('Cache-Control', 'no-store, private');

        $response->getBody()->write($text);

        return $response;
    }

    /** A bounded, control-character-stripped look at an upstream body that was not a recording. */
    private function snippet(ResponseInterface $response): string
    {
        return $this->clean($response->getBody()->read(self::MAX_ERROR_SNIPPET_BYTES));
    }

    private function clean(string $raw): string
    {
        $clean = (string) preg_replace('/[^\P{C}\n\r\t]/u', '', $raw);

        if ($clean === '' && $raw !== '') {
            return sprintf('(%d bytes of non-text content)', strlen($raw));
        }

        return $clean === '' ? '(empty body)' : substr($clean, 0, self::MAX_ERROR_SNIPPET_BYTES);
    }

    private function text(mixed $raw): string
    {
        return is_scalar($raw) ? trim((string) $raw) : '';
    }
}
