<?php

declare(strict_types=1);

namespace App\Tests\Unit\Document;

use App\Document\Application\DocumentProcessingParams;
use App\Document\Application\Validation\ImageInspector;
use App\Document\Application\Validation\MimeTypeDetector;
use App\Document\Application\Validation\UploadValidator;
use App\Document\Domain\DocumentKind;
use App\Document\Domain\Exception\FileTooLarge;
use App\Document\Domain\Exception\InvalidImage;
use App\Document\Domain\Exception\UnsupportedDocumentType;
use App\Tests\Support\DocumentFixtures;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertSame;

/**
 * The upload validator is the security gate for uploads, so these tests drive real files through it.
 */
final class UploadValidatorTest extends Unit
{
    private DocumentFixtures $fixtures;

    protected function _before(): void
    {
        $this->fixtures = new DocumentFixtures();
    }

    protected function _after(): void
    {
        $this->fixtures->cleanup();
    }

    private function validator(
        int $maxUploadBytes = 25 * 1024 * 1024,
        int $maxImageBytes = 8 * 1024 * 1024,
        int $imageMaxWidth = 12000,
        int $imageMaxHeight = 12000,
    ): UploadValidator {
        return new UploadValidator(
            new MimeTypeDetector(),
            new ImageInspector(),
            new DocumentProcessingParams($maxUploadBytes, $maxImageBytes, 200, $imageMaxWidth, $imageMaxHeight),
        );
    }

    public function testAcceptsAPdf(): void
    {
        $result = $this->validator()->validate($this->fixtures->pdf());

        assertSame('application/pdf', $result->mimeType);
        assertSame('pdf', $result->extension);
        assertSame(DocumentKind::Pdf, $result->kind);
    }

    public function testAcceptsAPng(): void
    {
        $result = $this->validator()->validate($this->fixtures->png());

        assertSame('image/png', $result->mimeType);
        assertSame('png', $result->extension);
        assertSame(DocumentKind::Image, $result->kind);
    }

    /**
     * The crux of upload safety: a PHP script renamed to .pdf is sniffed by content, not by name, and
     * rejected. The extension the client sent is never consulted.
     */
    public function testRejectsAPhpScriptDisguisedAsPdf(): void
    {
        $this->expectException(UnsupportedDocumentType::class);

        $this->validator()->validate($this->fixtures->phpDisguisedAsPdf());
    }

    public function testRejectsAnUnsupportedTextFile(): void
    {
        $this->expectException(UnsupportedDocumentType::class);

        $this->validator()->validate($this->fixtures->text());
    }

    public function testRejectsAFileOverTheUploadLimit(): void
    {
        // A 2 KB PDF against a 1 KB cap.
        $validator = $this->validator(maxUploadBytes: 1024);

        $this->expectException(FileTooLarge::class);

        $validator->validate($this->fixtures->write("%PDF-1.4\n" . str_repeat('x', 2048)));
    }

    public function testRejectsAnImageOverTheImageByteLimit(): void
    {
        // The general cap is generous, but the image-specific cap is tiny.
        $validator = $this->validator(maxImageBytes: 10);

        $this->expectException(FileTooLarge::class);

        $validator->validate($this->fixtures->png());
    }

    public function testRejectsAnImageOverTheDimensionLimit(): void
    {
        // A 1×1 PNG against a 0-pixel cap forces the dimension rejection path.
        $validator = $this->validator(imageMaxWidth: 0, imageMaxHeight: 0);

        $this->expectException(InvalidImage::class);

        $validator->validate($this->fixtures->png());
    }
}
