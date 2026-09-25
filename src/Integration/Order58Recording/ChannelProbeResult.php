<?php

declare(strict_types=1);

namespace App\Integration\Order58Recording;

use function number_format;
use function preg_replace;
use function strlen;
use function substr;

/**
 * What came back from one channel request, reduced to what a page may safely print.
 *
 * The recording itself is never in here — only the first few kilobytes, its size, and the verdicts
 * derived from them. A multi-megabyte body has no business in an HTML document, and a binary one would
 * corrupt the page.
 *
 * Deliberately absent: the response headers. The existing tool prints them all, which is right for a
 * general-purpose probe of an endpoint nobody understands yet. This tool tests a specific, known
 * request, and a header dump is a standing invitation to leak something a provider decides to start
 * sending. Content-Type, Content-Disposition and Content-Length are the three that answer a real
 * question, so those three are kept by name.
 */
final readonly class ChannelProbeResult
{
    public function __construct(
        public string $url,
        public int $status,
        public string $reason,
        public string $contentType,
        public string $contentDisposition,
        public ?string $contentLength,
        public int $bytes,
        /** The first bytes only. Never rendered raw — use {@see textPreview()}. */
        public string $sample,
        public WavSignature $wav,
        public ChannelDiagnosis $diagnosis,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** Whether there is something worth offering Play and Download for. */
    public function isPlayable(): bool
    {
        return $this->diagnosis->isSuccess();
    }

    public function sizeLabel(): string
    {
        if ($this->bytes === 0) {
            return '0 bytes';
        }

        return $this->bytes < 1048576
            ? number_format($this->bytes / 1024, 1) . ' KB'
            : number_format($this->bytes / 1048576, 2) . ' MB';
    }

    /**
     * A bounded, control-character-stripped look at a body that was **not** audio.
     *
     * Returns null for a real recording: printing the first kilobyte of a WAV would fill the page with
     * mojibake and tell nobody anything. This exists to show an error page or a JSON refusal, which is
     * the only case where the body is worth reading.
     */
    public function textPreview(int $maxBytes = 2000): ?string
    {
        if ($this->wav->isWav || $this->sample === '') {
            return null;
        }

        $clean = (string) preg_replace('/[^\P{C}\n\r\t]/u', '', $this->sample);

        if ($clean === '') {
            return null;
        }

        return strlen($clean) > $maxBytes ? substr($clean, 0, $maxBytes) . "\n…" : $clean;
    }
}
