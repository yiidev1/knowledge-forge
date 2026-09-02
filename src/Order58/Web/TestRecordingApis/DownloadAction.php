<?php

declare(strict_types=1);

namespace App\Order58\Web\TestRecordingApis;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

use function is_scalar;
use function preg_match;
use function preg_replace;
use function sprintf;
use function strlen;
use function substr;
use function trim;

/**
 * Streams one recording to the browser (GET /admin/order58/test-recording-apis/download).
 *
 * The companion to the diagnostic page, and deliberately a separate route: "Send Fetch Recording Request"
 * stays a test that reports what came back, and only this endpoint — reached by clicking *Download
 * recording* — ever hands the browser a file.
 *
 * It re-runs the same {@see RecordingApiProbe} call the page ran, against the same URL builder, using the
 * same validation ({@see RecordingRequest}) as the form. Nothing is persisted on the way through: the
 * upstream body is passed to the response as a stream, so a multi-megabyte recording is never buffered
 * whole, never written to disk, and never reaches the database. There is no temporary file to clean up
 * because there is no temporary file.
 *
 * Refusals are as important as the happy path. A non-2xx upstream, or a 2xx whose body is text, produces
 * a plain-text diagnostic rather than a download — a page of HTML saved as `test.wav` is exactly the
 * failure this endpoint must not produce.
 */
final readonly class DownloadAction
{
    /** Enough of an upstream error body to diagnose it, bounded so a runaway body cannot be echoed whole. */
    private const MAX_ERROR_SNIPPET_BYTES = 4096;

    public function __construct(
        private RecordingApiProbe $probe,
        private ResponseFactoryInterface $responseFactory,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();

        // No defaults here, unlike the form: the download link always carries all four, and a missing one
        // should be reported rather than silently filled in with a value the operator did not choose.
        $callSessionId = $this->text($params['call_session_id'] ?? null);
        $time = $this->text($params['time'] ?? null);
        $company = $this->text($params['company'] ?? null);
        $name = $this->text($params['name'] ?? null);

        $invalid = RecordingRequest::validate($callSessionId, $time, $company, $name);
        if ($invalid !== null) {
            return $this->diagnostic(400, "Invalid download request — nothing was sent to the API.\n\n" . $invalid);
        }

        try {
            $upstream = $this->probe->openRecording($callSessionId, $time, $company, $name);
        } catch (Throwable $e) {
            return $this->diagnostic(502, sprintf(
                "The recording request failed before any HTTP response arrived (DNS, TLS, connection or timeout).\n\n"
                    . "Request URL:\n%s\n\n%s: %s\n\nthrown in %s:%d\n\n%s",
                $this->probe->recordingUrl($callSessionId, $time, $company, $name),
                $e::class,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString(),
            ));
        }

        $url = $this->probe->recordingUrl($callSessionId, $time, $company, $name);
        $status = $upstream->getStatusCode();
        $contentType = $upstream->getHeaderLine('Content-Type');

        if ($status < 200 || $status >= 300) {
            return $this->diagnostic(502, sprintf(
                "The external API did not return a recording.\n\nRequest URL:\n%s\n\n"
                    . "Upstream HTTP status: %d %s\nUpstream Content-Type: %s\n\nUpstream response:\n%s",
                $url,
                $status,
                $upstream->getReasonPhrase(),
                $contentType === '' ? '(not sent)' : $contentType,
                $this->snippet($upstream),
            ));
        }

        // A 200 carrying HTML or JSON is an error dressed as success. Saving it as audio would produce a
        // file that plays nothing and hides the reason.
        if (BodyKind::isTextual($contentType)) {
            return $this->diagnostic(502, sprintf(
                "The external API returned HTTP %d but the body is text, not a recording.\n\nRequest URL:\n%s\n\n"
                    . "Upstream Content-Type: %s\n\nUpstream response:\n%s",
                $status,
                $url,
                $contentType,
                $this->snippet($upstream),
            ));
        }

        $filename = DownloadFilename::fromContentDisposition(
            $upstream->getHeaderLine('Content-Disposition'),
            $callSessionId,
        );

        $response = $this->responseFactory
            ->createResponse(200)
            // Not echoing the upstream type: it is a hint from an external server, and octet-stream is
            // the honest description of bytes we are handing over without interpreting them.
            ->withHeader('Content-Type', 'application/octet-stream')
            ->withHeader('Content-Disposition', sprintf('attachment; filename="%s"', $filename))
            ->withBody($upstream->getBody());

        // Preserved only when it is a plain integer, so a malformed upstream value cannot desync the body.
        $length = $upstream->getHeaderLine('Content-Length');
        if (preg_match('/^\d+$/', $length) === 1) {
            $response = $response->withHeader('Content-Length', $length);
        }

        return $response;
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
            ->withHeader('Content-Disposition', 'inline');

        $response->getBody()->write($text);

        return $response;
    }

    /** A bounded, control-character-stripped look at an upstream body that was not a recording. */
    private function snippet(ResponseInterface $response): string
    {
        $body = $response->getBody();
        $raw = $body->read(self::MAX_ERROR_SNIPPET_BYTES);

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
