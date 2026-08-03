<?php

declare(strict_types=1);

namespace App\Document\Application;

use App\Document\Application\Storage\DocumentStorageInterface;
use App\Document\Domain\CanonicalDocument;
use App\Document\Domain\DocumentKind;
use App\Document\Domain\DocumentRepositoryInterface;
use App\Document\Domain\Exception\DocumentNotFound;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

use function strlen;
use function str_ends_with;
use function strtolower;

/**
 * Serves the canonical local source for View (inline) or Download (attachment).
 */
final readonly class ServeCanonicalDocumentService
{
    public function __construct(
        private DocumentRepositoryInterface $documents,
        private DocumentStorageInterface $storage,
        private ResponseFactoryInterface $responses,
    ) {}

    public function find(int $documentId, int $knowledgeBaseId): CanonicalDocument
    {
        $document = $this->documents->findCanonicalForKnowledgeBase($documentId, $knowledgeBaseId);
        if ($document === null) {
            throw DocumentNotFound::inKnowledgeBase($documentId, $knowledgeBaseId);
        }

        return $document;
    }

    /**
     * Textual body for the HTML View page. Escaped by the template — never rendered as HTML.
     */
    public function textBody(CanonicalDocument $document): string
    {
        if ($document->isManualText() && $document->sourceText !== null && $document->sourceText !== '') {
            return $document->sourceText;
        }

        if (!$this->storage->exists($document->storedPath)) {
            throw DocumentNotFound::inKnowledgeBase($document->id, $document->knowledgeBaseId);
        }

        $stream = $this->storage->readStream($document->storedPath);
        $contents = $stream->getContents();
        $stream->close();

        return $contents;
    }

    public function streamInline(CanonicalDocument $document): ResponseInterface
    {
        return $this->stream($document, 'inline');
    }

    public function streamAttachment(CanonicalDocument $document): ResponseInterface
    {
        return $this->stream($document, 'attachment');
    }

    public function downloadFilename(CanonicalDocument $document): string
    {
        if ($document->isManualText() || $document->isOrder58() || $document->isUploadedText()) {
            $base = ContentDisposition::sanitizeFilename($document->displayTitle());
            $ext = $document->extension !== '' ? $document->extension : 'md';

            return str_ends_with(strtolower($base), '.' . strtolower($ext)) ? $base : $base . '.' . $ext;
        }

        return ContentDisposition::sanitizeFilename($document->originalFilename);
    }

    public function isBinaryView(CanonicalDocument $document): bool
    {
        return $document->kind === DocumentKind::Pdf || $document->kind === DocumentKind::Image;
    }

    private function stream(CanonicalDocument $document, string $disposition): ResponseInterface
    {
        if (!$this->storage->exists($document->storedPath)) {
            if ($document->isManualText() && $document->sourceText !== null && $document->sourceText !== '') {
                return $this->fromString(
                    $document->sourceText,
                    $document->mimeType !== '' ? $document->mimeType : 'text/markdown',
                    $disposition,
                    $this->downloadFilename($document),
                );
            }

            throw DocumentNotFound::inKnowledgeBase($document->id, $document->knowledgeBaseId);
        }

        $stream = $this->storage->readStream($document->storedPath);
        $mime = $document->mimeType !== '' ? $document->mimeType : 'application/octet-stream';

        return $this->responses->createResponse(200)
            ->withHeader('Content-Type', $mime)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Disposition', ContentDisposition::header($disposition, $this->downloadFilename($document)))
            ->withHeader('Content-Length', (string) $document->sizeBytes)
            ->withBody($stream);
    }

    private function fromString(string $contents, string $mime, string $disposition, string $filename): ResponseInterface
    {
        $response = $this->responses->createResponse(200);
        $response->getBody()->write($contents);

        return $response
            ->withHeader('Content-Type', $mime)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Disposition', ContentDisposition::header($disposition, $filename))
            ->withHeader('Content-Length', (string) strlen($contents));
    }
}
