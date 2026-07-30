<?php

declare(strict_types=1);

namespace App\Tests\Unit\Document;

use App\Document\Application\Pdf\PdfDecision;
use App\Document\Application\Pdf\PdfIngestionPolicy;
use App\Document\Application\Pdf\PdfProbeResult;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertSame;

/**
 * The corrected PDF policy: direct indexing requires positive evidence of a text layer; absence of
 * evidence routes to vision; vision-impossible (too big / too many pages) routes to manual review.
 */
final class PdfIngestionPolicyTest extends Unit
{
    private const MB = 1048576;

    private function policy(): PdfIngestionPolicy
    {
        // minCharsPerPage=100, visionMaxPages=50, visionMaxBytes=25 MB
        return new PdfIngestionPolicy(100, 50, 25 * self::MB);
    }

    public function testProbedWithEnoughTextIsIndexedDirectly(): void
    {
        $decision = $this->policy()->decide(new PdfProbeResult(true, 3, 500), 2 * self::MB);

        assertSame(PdfDecision::INDEX_DIRECT, $decision->action);
    }

    public function testProbedButBelowThresholdGoesToVision(): void
    {
        $decision = $this->policy()->decide(new PdfProbeResult(true, 3, 10), 2 * self::MB);

        assertSame(PdfDecision::VISION, $decision->action);
    }

    public function testProbeFailedGoesToVisionNotDirect(): void
    {
        // The critical corrected case: unknown text layer must never be indexed directly.
        $decision = $this->policy()->decide(PdfProbeResult::notProbed(), 2 * self::MB);

        assertSame(PdfDecision::VISION, $decision->action);
    }

    public function testVisionImpossibleBecauseTooLargeGoesToManualReview(): void
    {
        $decision = $this->policy()->decide(PdfProbeResult::notProbed(), 40 * self::MB);

        assertSame(PdfDecision::MANUAL_REVIEW, $decision->action);
        assertSame(true, $decision->reason !== null && $decision->reason !== '');
    }

    public function testVisionImpossibleBecauseTooManyPagesGoesToManualReview(): void
    {
        $decision = $this->policy()->decide(new PdfProbeResult(true, 200, 5), 2 * self::MB);

        assertSame(PdfDecision::MANUAL_REVIEW, $decision->action);
    }
}
