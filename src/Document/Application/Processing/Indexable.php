<?php

declare(strict_types=1);

namespace App\Document\Application\Processing;

use App\Document\Domain\IndexedFileRole;
use Psr\Http\Message\StreamInterface;

/**
 * One unit of content a processor wants indexed: the bytes to upload, the filename to give the provider,
 * the role it plays for its document, and the derived-file path when the content was generated rather
 * than uploaded as-is.
 */
final readonly class Indexable
{
    public function __construct(
        public IndexedFileRole $role,
        public StreamInterface $content,
        public string $filename,
        public ?string $derivedPath,
    ) {}
}
