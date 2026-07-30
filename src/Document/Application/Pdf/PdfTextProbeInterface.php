<?php

declare(strict_types=1);

namespace App\Document\Application\Pdf;

/**
 * Inspects a PDF for an extractable text layer. Abstracted so the ingestion policy can be tested without
 * a PDF parser, and so the parser can be swapped.
 */
interface PdfTextProbeInterface
{
    public function probe(string $absolutePath): PdfProbeResult;
}
