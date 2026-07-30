<?php

declare(strict_types=1);

namespace App\Document\Application\Processing;

use App\Ai\Contract\Exception\AiException;
use App\Document\Domain\Document;
use App\Document\Domain\Exception\ManualReviewRequired;

/**
 * Turns a document into the content that should be indexed for it.
 *
 * One implementation per {@see \App\Document\Domain\DocumentKind}. A processor decides HOW a document
 * becomes searchable — a text PDF is indexed directly, an image or scanned PDF is converted to Markdown
 * first — but it does not talk to the vector store; that is the processing service's job. Vision
 * extraction is made idempotent by persisting the derived Markdown, so a retry reuses it rather than
 * paying for the model again.
 */
interface DocumentProcessorInterface
{
    public function supports(Document $document): bool;

    /**
     * @return list<Indexable> The content to upload and attach for this document.
     *
     * @throws ManualReviewRequired when the document cannot be ingested within the configured limits
     *                              (a permanent failure — no retry).
     * @throws AiException          on a transient provider failure during extraction (the service
     *                              retries with backoff).
     */
    public function produce(Document $document): array;
}
