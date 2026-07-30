<?php

declare(strict_types=1);

namespace App\Document\Application\Pdf;

/**
 * The outcome of {@see PdfIngestionPolicy}: index the PDF directly, convert it via vision, or fail it for
 * manual review with a specific, actionable reason.
 */
final readonly class PdfDecision
{
    public const INDEX_DIRECT = 'index_direct';
    public const VISION = 'vision';
    public const MANUAL_REVIEW = 'manual_review';

    private function __construct(
        public string $action,
        public ?string $reason,
    ) {}

    public static function indexDirect(): self
    {
        return new self(self::INDEX_DIRECT, null);
    }

    public static function vision(): self
    {
        return new self(self::VISION, null);
    }

    public static function manualReview(string $reason): self
    {
        return new self(self::MANUAL_REVIEW, $reason);
    }
}
