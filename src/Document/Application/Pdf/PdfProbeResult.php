<?php

declare(strict_types=1);

namespace App\Document\Application\Pdf;

/**
 * What a text-layer probe learned about a PDF.
 *
 * `probed` is false when the probe was skipped (file too large) or failed (unparseable). `pageCount`
 * and `charsPerPage` are null when unknown. The ingestion policy never treats "unknown" as "has text" —
 * that is the whole point of the corrected PDF policy.
 */
final readonly class PdfProbeResult
{
    public function __construct(
        public bool $probed,
        public ?int $pageCount,
        public ?int $charsPerPage,
    ) {}

    public static function notProbed(): self
    {
        return new self(false, null, null);
    }
}
