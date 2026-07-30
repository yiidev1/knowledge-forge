<?php

declare(strict_types=1);

namespace App\Document\Infrastructure\Pdf;

use App\Document\Application\Pdf\PdfProbeResult;
use App\Document\Application\Pdf\PdfTextProbeInterface;
use Smalot\PdfParser\Parser;
use Throwable;

use function count;
use function filesize;
use function intdiv;
use function mb_strlen;
use function trim;

/**
 * Probes a PDF's text layer with the pure-PHP smalot/pdfparser.
 *
 * Bounded by a byte limit: a PDF larger than the limit is reported as not-probed rather than fully
 * parsed into memory, and a parser failure is likewise reported as not-probed. In both cases the
 * ingestion policy routes the document to vision or manual review — it never assumes text is present.
 */
final readonly class SmalotPdfTextProbe implements PdfTextProbeInterface
{
    public function __construct(
        private int $maxProbeBytes,
    ) {}

    public function probe(string $absolutePath): PdfProbeResult
    {
        $size = @filesize($absolutePath);
        if ($size === false || $size > $this->maxProbeBytes) {
            return PdfProbeResult::notProbed();
        }

        try {
            $pdf = (new Parser())->parseFile($absolutePath);
            $pageCount = count($pdf->getPages());
            $chars = mb_strlen(trim($pdf->getText()));
            $charsPerPage = $pageCount > 0 ? intdiv($chars, $pageCount) : $chars;

            return new PdfProbeResult(true, $pageCount, $charsPerPage);
        } catch (Throwable) {
            return PdfProbeResult::notProbed();
        }
    }
}
