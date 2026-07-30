<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Ai;

use App\Ai\Contract\DocumentContentExtractorInterface;
use App\Ai\Contract\Dto\ExtractionResult;
use App\Ai\Contract\Dto\TokenUsage;
use App\Ai\Contract\Exception\AiException;
use Psr\Http\Message\StreamInterface;

/**
 * Fake vision extractor: returns canned Markdown so the image / scanned-PDF paths can be tested without
 * a model, and can be told to throw to exercise the transient-vs-permanent failure handling.
 */
final class FakeDocumentContentExtractor implements DocumentContentExtractorInterface
{
    public int $imageCalls = 0;

    public int $pdfCalls = 0;

    private ?AiException $throw = null;

    public function __construct(
        private readonly string $markdown = "# Extracted\n\nSome text.",
    ) {}

    public function willThrow(AiException $exception): void
    {
        $this->throw = $exception;
    }

    public function extractFromImage(string $imageDataUrl, string $prompt): ExtractionResult
    {
        $this->imageCalls++;
        $this->maybeThrow();

        return $this->result();
    }

    public function extractFromPdf(StreamInterface $pdf, string $filename, string $prompt): ExtractionResult
    {
        $this->pdfCalls++;
        $this->maybeThrow();

        return $this->result();
    }

    private function result(): ExtractionResult
    {
        return new ExtractionResult($this->markdown, new TokenUsage(10, 20, 30), 'resp_fake', 'fake-vision');
    }

    private function maybeThrow(): void
    {
        if ($this->throw !== null) {
            $exception = $this->throw;
            $this->throw = null;

            throw $exception;
        }
    }
}
