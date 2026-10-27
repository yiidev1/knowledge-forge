<?php

declare(strict_types=1);

namespace App\Order58\Application\RecordingStream;

use Psr\Http\Message\StreamInterface;

/**
 * One fetch's outcome: a complete WAV ready to serve, or the reason there is none.
 *
 * The body is a seekable, request-scoped spool — a few megabytes in memory, the rest in an anonymous
 * temporary file the runtime deletes when the request ends. Nothing about it outlives the response.
 */
final readonly class RecordingFetch
{
    private function __construct(
        public ?StreamInterface $body,
        public int $size,
        public ?RecordingFetchFailure $failure,
        /** What the provider answered, when it was asked at all. */
        public ?int $upstreamStatus,
        /** Seconds to wait before asking again, when the reason says so. */
        public ?int $retryAfterSeconds,
        /**
         * A short, safe diagnostic: a WAV verdict or an exception class.
         *
         * Never the URL and never the provider's body — the first carries a phone number and the second
         * can carry anything.
         */
        public string $detail,
    ) {}

    public static function ok(StreamInterface $body, int $size, int $upstreamStatus): self
    {
        return new self($body, $size, null, $upstreamStatus, null, '');
    }

    public static function failed(
        RecordingFetchFailure $failure,
        ?int $upstreamStatus = null,
        ?int $retryAfterSeconds = null,
        string $detail = '',
    ): self {
        return new self(null, 0, $failure, $upstreamStatus, $retryAfterSeconds, $detail);
    }

    /** @psalm-assert-if-true !null $this->body */
    public function isOk(): bool
    {
        return $this->failure === null && $this->body !== null;
    }
}
