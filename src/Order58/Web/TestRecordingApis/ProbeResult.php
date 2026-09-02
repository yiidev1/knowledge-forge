<?php

declare(strict_types=1);

namespace App\Order58\Web\TestRecordingApis;

use function implode;
use function ksort;
use function sprintf;

/**
 * One completed outbound call, recorded exactly as it came back: status line, every response header, and
 * as much of the body as the probe was willing to hold in memory.
 *
 * Nothing here is interpreted. `$preview` is bytes, not text — the template decides how to show it, and
 * {@see $isBinary} is the probe's read on whether showing it as text would be sensible.
 */
final readonly class ProbeResult
{
    /**
     * @param array<array-key, array<array-key, string>> $headers every response header, as the server sent them
     * @param string $preview the leading bytes of the body (all of it unless {@see $truncated})
     * @param int $bytes total bytes received, including any beyond the preview
     */
    public function __construct(
        public string $url,
        public int $status,
        public string $reason,
        public array $headers,
        public string $contentType,
        public string $contentDisposition,
        public ?string $contentLength,
        public int $bytes,
        public string $preview,
        public bool $truncated,
        public bool $isBinary,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** True when the body can be shown as text: not binary, and not cut off mid-way. */
    public function isReadableText(): bool
    {
        return !$this->isBinary && !$this->truncated;
    }

    /** Every response header as `Name: value` lines, one per value, sorted for scanability. */
    public function headerLines(): string
    {
        $headers = $this->headers;
        ksort($headers);

        $lines = [];
        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                $lines[] = sprintf('%s: %s', (string) $name, $value);
            }
        }

        return implode("\n", $lines);
    }
}
