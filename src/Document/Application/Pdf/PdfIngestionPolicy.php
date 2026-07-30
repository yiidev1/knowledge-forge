<?php

declare(strict_types=1);

namespace App\Document\Application\Pdf;

/**
 * Decides how a PDF should be ingested, from its probe result and size — the corrected policy from the
 * plan, made pure so every branch is unit-testable.
 *
 * The one inviolable rule: a PDF is indexed directly ONLY on positive evidence of a text layer. Absence
 * of evidence (probe skipped or failed) is never treated as "has text"; it routes to vision, and only
 * when vision is impossible within the limits does the document go to manual review. A PDF is thus
 * never silently indexed as empty.
 */
final readonly class PdfIngestionPolicy
{
    public function __construct(
        private int $minCharsPerPage,
        private int $visionMaxPages,
        private int $visionMaxBytes,
    ) {}

    public function decide(PdfProbeResult $probe, int $sizeBytes): PdfDecision
    {
        // Positive evidence of a usable text layer → index the PDF directly.
        if ($probe->probed && $probe->charsPerPage !== null && $probe->charsPerPage >= $this->minCharsPerPage) {
            return PdfDecision::indexDirect();
        }

        // Otherwise vision extraction, guarded by cost limits.
        if ($sizeBytes > $this->visionMaxBytes) {
            return PdfDecision::manualReview(
                sprintf(
                    'Could not confirm a text layer and the file (%.1f MB) exceeds the %.1f MB vision limit. Split the PDF and re-upload.',
                    $sizeBytes / 1048576,
                    $this->visionMaxBytes / 1048576,
                ),
            );
        }

        if ($probe->pageCount !== null && $probe->pageCount > $this->visionMaxPages) {
            return PdfDecision::manualReview(
                sprintf(
                    'Could not confirm a text layer and the document has %d pages, over the %d-page vision limit. Split the PDF and re-upload.',
                    $probe->pageCount,
                    $this->visionMaxPages,
                ),
            );
        }

        return PdfDecision::vision();
    }
}
