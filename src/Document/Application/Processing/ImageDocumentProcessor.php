<?php

declare(strict_types=1);

namespace App\Document\Application\Processing;

use App\Ai\Contract\DocumentContentExtractorInterface;
use App\Document\Application\Storage\DocumentStorageInterface;
use App\Document\Domain\Document;
use App\Document\Domain\DocumentKind;
use App\Document\Domain\IndexedFileRole;

use function base64_encode;
use function sprintf;

/**
 * Ingests images. An image is never a File Search document: it is transcribed to Markdown by the vision
 * model and the Markdown is indexed, while the citation still resolves to the original image filename.
 *
 * The image is sent inline as a base64 data URL (bounded by the image upload size limit), so no
 * temporary remote file is created. The derived Markdown is persisted and reused on retry.
 */
final readonly class ImageDocumentProcessor implements DocumentProcessorInterface
{
    public function __construct(
        private DocumentStorageInterface $storage,
        private DocumentContentExtractorInterface $extractor,
    ) {}

    public function supports(Document $document): bool
    {
        return $document->kind() === DocumentKind::Image;
    }

    public function produce(Document $document): array
    {
        $derivedPath = $this->storage->derivedMarkdownPath($document->knowledgeBaseId(), $document->storageToken());

        if (!$this->storage->exists($derivedPath)) {
            $dataUrl = sprintf(
                'data:%s;base64,%s',
                $document->mimeType(),
                base64_encode((string) $this->storage->readStream($document->storedPath())),
            );

            $result = $this->extractor->extractFromImage($dataUrl, ExtractionPrompts::image());
            $this->storage->putContents($derivedPath, $result->markdown);
        }

        return [new Indexable(
            IndexedFileRole::DerivedMarkdown,
            $this->storage->readStream($derivedPath),
            $document->storageToken() . '.md',
            $derivedPath,
        )];
    }
}
