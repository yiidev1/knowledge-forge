<?php

declare(strict_types=1);

namespace App\Document\Application\Processing;

use App\Ai\Contract\DocumentContentExtractorInterface;
use App\Document\Application\Pdf\PdfDecision;
use App\Document\Application\Pdf\PdfIngestionPolicy;
use App\Document\Application\Pdf\PdfTextProbeInterface;
use App\Document\Application\Storage\DocumentStorageInterface;
use App\Document\Domain\Document;
use App\Document\Domain\DocumentKind;
use App\Document\Domain\Exception\ManualReviewRequired;
use App\Document\Domain\IndexedFileRole;

/**
 * Ingests PDFs. A PDF with a real text layer is indexed directly; a scanned one is transcribed to
 * Markdown by the vision model and that Markdown is indexed instead; one that is too large or too long
 * for vision is failed for manual review. The direct-vs-vision-vs-fail choice is made by the pure
 * {@see PdfIngestionPolicy} from a text probe, so an unknown PDF is never indexed as if it had text.
 *
 * Vision extraction is written to a derived file and reused on retry, so a transient upload failure does
 * not re-run (and re-bill) the model.
 */
final readonly class PdfDocumentProcessor implements DocumentProcessorInterface
{
    public function __construct(
        private PdfTextProbeInterface $probe,
        private PdfIngestionPolicy $policy,
        private DocumentStorageInterface $storage,
        private DocumentContentExtractorInterface $extractor,
    ) {}

    public function supports(Document $document): bool
    {
        return $document->kind() === DocumentKind::Pdf;
    }

    public function produce(Document $document): array
    {
        $probe = $this->probe->probe($this->storage->absolutePath($document->storedPath()));
        $decision = $this->policy->decide($probe, $document->sizeBytes());

        return match ($decision->action) {
            PdfDecision::INDEX_DIRECT => [$this->directSource($document)],
            PdfDecision::VISION => [$this->visionMarkdown($document)],
            default => throw new ManualReviewRequired($decision->reason ?? 'This PDF requires manual review.'),
        };
    }

    private function directSource(Document $document): Indexable
    {
        return new Indexable(
            IndexedFileRole::Source,
            $this->storage->readStream($document->storedPath()),
            $document->storageToken() . '.pdf',
            null,
        );
    }

    private function visionMarkdown(Document $document): Indexable
    {
        $derivedPath = $this->storage->derivedMarkdownPath($document->knowledgeBaseId(), $document->storageToken());

        if (!$this->storage->exists($derivedPath)) {
            $result = $this->extractor->extractFromPdf(
                $this->storage->readStream($document->storedPath()),
                $document->originalFilename(),
                ExtractionPrompts::pdf(),
            );
            $this->storage->putContents($derivedPath, $result->markdown);
        }

        return new Indexable(
            IndexedFileRole::DerivedMarkdown,
            $this->storage->readStream($derivedPath),
            $document->storageToken() . '.md',
            $derivedPath,
        );
    }
}
