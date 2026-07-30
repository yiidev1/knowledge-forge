<?php

declare(strict_types=1);

namespace App\Document\Application\Validation;

use App\Document\Domain\DocumentKind;

/**
 * The trusted facts about an upload after validation: its server-detected type, canonical extension,
 * ingestion kind and size. Produced by {@see UploadValidator} and consumed by the upload service, so
 * nothing downstream re-derives these from untrusted input.
 */
final readonly class ValidatedUpload
{
    public function __construct(
        public string $mimeType,
        public string $extension,
        public DocumentKind $kind,
        public int $sizeBytes,
    ) {}
}
