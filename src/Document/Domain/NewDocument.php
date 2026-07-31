<?php

declare(strict_types=1);

namespace App\Document\Domain;

/**
 * The fully-validated data needed to persist a new document.
 *
 * A value object rather than a long argument list, so the upload service produces one well-formed
 * thing and the repository consumes it without ambiguity about argument order.
 */
final readonly class NewDocument
{
    public function __construct(
        public int $knowledgeBaseId,
        public string $originalFilename,
        public string $storedPath,
        public string $storageToken,
        public string $mimeType,
        public string $extension,
        public int $sizeBytes,
        public string $checksumSha256,
        public DocumentKind $kind,
        // Provenance for the unified document model. Defaulted so existing binary-upload construction and
        // tests keep working; the upload service now supplies the accurate value (uploaded_pdf/image/text).
        public DocumentSourceType $sourceType = DocumentSourceType::UploadedPdf,
        // Human-readable title; falls back to the original filename when null.
        public ?string $title = null,
        // The original submitted text of a manual-text document, kept so it can be edited. NULL for
        // uploads and Order58-generated documents.
        public ?string $sourceText = null,
    ) {}
}
