<?php

declare(strict_types=1);

namespace App\Order58\Web\TestRecordingChannels;

use function preg_match;
use function sprintf;
use function str_contains;
use function strtolower;

/**
 * Turns an outcome into one sentence an operator can act on.
 *
 * The test page's whole job is answering "what happened, and what do I do now?". A status code alone
 * does not: 403 from this provider almost always means the IP allowlist, which is a request to make of
 * the client rather than anything to fix in the code — and that distinction is the difference between
 * a five-minute email and an afternoon of debugging.
 *
 * Every verdict names what to do next. None of them echoes a response header, a token or a credential.
 */
final readonly class ChannelDiagnosis
{
    public const OK = 'ok';
    public const IP_NOT_WHITELISTED = 'ip-not-whitelisted';
    public const FORBIDDEN = 'forbidden';
    public const UNAUTHORIZED = 'unauthorized';
    public const NOT_FOUND = 'not-found';
    public const TIMEOUT = 'timeout';
    public const CONNECTION_FAILED = 'connection-failed';
    public const EMPTY_BODY = 'empty-body';
    public const NOT_AUDIO = 'not-audio';
    public const TRUNCATED = 'truncated';
    public const SERVER_ERROR = 'server-error';
    public const UNEXPECTED_STATUS = 'unexpected-status';
    public const NOT_CONFIGURED = 'not-configured';

    private function __construct(
        public string $kind,
        public string $headline,
        public string $advice,
    ) {}

    public function isSuccess(): bool
    {
        return $this->kind === self::OK;
    }

    /** Whether the page should style this as an outright failure rather than a caveat. */
    public function isFailure(): bool
    {
        return $this->kind !== self::OK && $this->kind !== self::TRUNCATED;
    }

    /**
     * Classify an HTTP response that did arrive.
     *
     * @param string $bodySample the first bytes only — never the whole recording
     */
    public static function fromResponse(
        int $status,
        string $contentType,
        string $bodySample,
        int $totalBytes,
    ): self {
        if ($status === 401) {
            return new self(
                self::UNAUTHORIZED,
                'HTTP 401 — the recording API rejected this request as unauthenticated.',
                'The confirmed recording endpoint is gated by an IP allowlist and sends no credential, so a '
                . '401 suggests the provider has changed how it authenticates. Ask the client what this '
                . 'endpoint now expects.',
            );
        }

        if ($status === 403) {
            // The provider answers `403 Forbidden - IP not authorized: <address>`. Detected from the body
            // rather than assumed from the status, so a genuine permissions 403 is not mislabelled.
            return self::looksLikeIpRejection($bodySample)
                ? new self(
                    self::IP_NOT_WHITELISTED,
                    'Recording API rejected this server/IP.',
                    'The external recording service requires this IP to be whitelisted. Send the client this '
                    . "server's public IP address and ask them to add it. Nothing in this application can or "
                    . 'should work around it.',
                )
                : new self(
                    self::FORBIDDEN,
                    'HTTP 403 — the recording API refused this request.',
                    'The response does not look like an IP allowlist rejection, so the refusal is about this '
                    . 'recording rather than this server. Check the recording id, company and date with the client.',
                );
        }

        if ($status === 404) {
            return new self(
                self::NOT_FOUND,
                'HTTP 404 — the recording API has no file at this address.',
                'Either the recording does not exist for these parameters, or — for a caller/callee channel — '
                . 'the request format has not been confirmed yet and this URL is a guess. Check which candidate '
                . 'mapping is in use below before concluding the file is missing.',
            );
        }

        if ($status === 408) {
            return new self(
                self::TIMEOUT,
                'HTTP 408 — the recording API timed out waiting for this request.',
                'Transient. Try again; if it persists, report it to the client with the time it happened.',
            );
        }

        if ($status >= 500) {
            return new self(
                self::SERVER_ERROR,
                sprintf('HTTP %d — the recording API failed on its side.', $status),
                'Nothing to fix here. Retry shortly, and report it to the client if it continues.',
            );
        }

        if ($status < 200 || $status >= 300) {
            return new self(
                self::UNEXPECTED_STATUS,
                sprintf('HTTP %d — an unexpected response from the recording API.', $status),
                'The raw response is shown below. Send it to the client if the meaning is not obvious.',
            );
        }

        if ($totalBytes === 0) {
            return new self(
                self::EMPTY_BODY,
                'HTTP 200 with an empty body.',
                'The API reported success and sent nothing. Treat this as a failure — an empty file saved as '
                . '.wav would look like a working download and play silence.',
            );
        }

        $wav = WavSignature::inspect($bodySample, $totalBytes);

        if (!$wav->isWav) {
            return new self(
                self::NOT_AUDIO,
                'HTTP 200, but the body is not a WAV file.',
                $wav->summary . ' A textual body here is usually an error page returned with a success status; '
                . 'the preview below will show it.',
            );
        }

        if ($wav->looksTruncated) {
            return new self(
                self::TRUNCATED,
                'A WAV file, but shorter than its own header declares.',
                $wav->summary . ' The transfer was probably cut short. Retry before trusting the audio.',
            );
        }

        return new self(
            self::OK,
            sprintf('HTTP %d — a complete WAV file was returned.', $status),
            'Play or download it below to confirm it is the channel you asked for.',
        );
    }

    /** No HTTP response arrived at all: DNS, TLS, refused connection, or a client-side timeout. */
    public static function fromTransportFailure(string $message): self
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'timed out') || str_contains($lower, 'timeout')) {
            return new self(
                self::TIMEOUT,
                'The request timed out before the API answered.',
                'No HTTP response arrived. The host may be slow, unreachable from this network, or silently '
                . 'dropping traffic from a non-whitelisted address.',
            );
        }

        return new self(
            self::CONNECTION_FAILED,
            'The request failed before any HTTP response arrived.',
            'A DNS, TLS or connection-level failure. From a machine that is not whitelisted this is also what '
            . 'a silently dropped connection looks like.',
        );
    }

    /** The caller/callee request format is not resolved, so nothing was sent. */
    public static function notConfigured(RecordingChannel $channel): self
    {
        return new self(
            self::NOT_CONFIGURED,
            sprintf('No request was made — the %s channel format is not confirmed.', $channel->label()),
            'Ask the client for one working URL for a caller or callee file, then set '
            . 'ChannelRequestMapping::CANDIDATE accordingly.',
        );
    }

    /**
     * Whether a 403 body is the provider's IP allowlist rejection.
     *
     * Matched on the provider's actual wording — `403 Forbidden - IP not authorized: <address>` — but
     * loosely enough to survive rephrasing, and specifically enough not to claim an allowlist problem
     * for an unrelated refusal.
     */
    private static function looksLikeIpRejection(string $body): bool
    {
        return preg_match('/\bip\b[^\n]{0,40}\b(not\s+authoriz|not\s+allow|whitelist|allowlist)/i', $body) === 1
            || preg_match('/\b(whitelist|allowlist)[^\n]{0,40}\bip\b/i', $body) === 1;
    }
}
