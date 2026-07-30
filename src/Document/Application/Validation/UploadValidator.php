<?php

declare(strict_types=1);

namespace App\Document\Application\Validation;

use App\Document\Application\DocumentProcessingParams;
use App\Document\Domain\DocumentKind;
use App\Document\Domain\Exception\FileTooLarge;
use App\Document\Domain\Exception\InvalidImage;
use App\Document\Domain\Exception\UnsupportedDocumentType;

use function filesize;

/**
 * Validates an already-stored temporary file against every upload rule, in a deliberate order:
 * cheapest and most decisive checks first.
 *
 * Operating on a filesystem path (not a PSR-7 upload) keeps this fully testable with fixture files and
 * means the checks run against the exact bytes that will be stored — after the stream has been captured
 * — rather than against a browser's claims.
 */
final readonly class UploadValidator
{
    public function __construct(
        private MimeTypeDetector $mimeDetector,
        private ImageInspector $imageInspector,
        private DocumentProcessingParams $params,
    ) {}

    /**
     * @throws FileTooLarge
     * @throws UnsupportedDocumentType
     * @throws InvalidImage
     */
    public function validate(string $absolutePath): ValidatedUpload
    {
        $size = $this->size($absolutePath);

        // 1. Overall size cap first — it is the cheapest check and rejects the largest abuse.
        if ($size > $this->params->maxUploadBytes) {
            throw FileTooLarge::limit($size, $this->params->maxUploadBytes);
        }

        // 2. Server-side content sniffing decides the type. The client MIME and filename are ignored.
        $mime = $this->mimeDetector->detect($absolutePath);
        if (!SupportedFileTypes::isSupported($mime)) {
            throw UnsupportedDocumentType::detected($mime, SupportedFileTypes::acceptedMimeTypes());
        }

        /** @var string $extension */
        $extension = SupportedFileTypes::extensionFor($mime);
        /** @var DocumentKind $kind */
        $kind = SupportedFileTypes::kindFor($mime);

        // 3. Images get a stricter byte cap and must be a decodable raster within the pixel limits.
        if ($kind === DocumentKind::Image) {
            $this->validateImage($absolutePath, $size);
        }

        return new ValidatedUpload($mime, $extension, $kind, $size);
    }

    private function validateImage(string $absolutePath, int $size): void
    {
        if ($size > $this->params->maxImageBytes) {
            throw FileTooLarge::limit($size, $this->params->maxImageBytes);
        }

        $dimensions = $this->imageInspector->inspect($absolutePath);
        if ($dimensions === null) {
            throw InvalidImage::undecodable();
        }

        if ($dimensions['width'] > $this->params->imageMaxWidth
            || $dimensions['height'] > $this->params->imageMaxHeight) {
            throw InvalidImage::tooLarge(
                $dimensions['width'],
                $dimensions['height'],
                $this->params->imageMaxWidth,
                $this->params->imageMaxHeight,
            );
        }
    }

    private function size(string $absolutePath): int
    {
        $size = @filesize($absolutePath);

        return $size === false ? 0 : $size;
    }
}
