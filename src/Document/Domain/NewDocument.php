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
    ) {}
}
