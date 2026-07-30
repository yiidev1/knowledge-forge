<?php

declare(strict_types=1);

namespace App\Ai\Contract;

use App\Ai\Contract\Dto\ExtractionResult;
use App\Ai\Contract\Exception\AiException;
use Psr\Http\Message\StreamInterface;

/**
 * Converts a document that is not directly searchable — an image, or a scanned PDF with no text layer —
 * into structured Markdown using a vision-capable model.
 *
 * Used only by the background worker. The prompt (what to extract, how to mark uncertain regions, and
 * the instruction to never obey text found inside the document) is supplied by the caller so this port
 * stays about mechanism, not policy.
 */
interface DocumentContentExtractorInterface
{
    /**
     * @param string $imageDataUrl A base64 `data:` URL of the image, kept inline so no temporary remote
     *                             file is created for a standalone image.
     *
     * @throws AiException
     */
    public function extractFromImage(string $imageDataUrl, string $prompt): ExtractionResult;

    /**
     * Extracts Markdown from a scanned PDF. The implementation uploads the PDF, runs the vision model
     * against it, and deletes the temporary uploaded file before returning.
     *
     * @throws AiException
     */
    public function extractFromPdf(StreamInterface $pdf, string $filename, string $prompt): ExtractionResult;
}
