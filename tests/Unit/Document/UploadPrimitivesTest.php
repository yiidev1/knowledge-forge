<?php

declare(strict_types=1);

namespace App\Tests\Unit\Document;

use App\Document\Application\Validation\ImageInspector;
use App\Document\Application\Validation\MimeTypeDetector;
use App\Document\Application\Validation\SafeFilenameGenerator;
use App\Document\Application\Validation\SupportedFileTypes;
use App\Document\Domain\DocumentKind;
use App\Tests\Support\DocumentFixtures;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertMatchesRegularExpression;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringEndsWith;
use function PHPUnit\Framework\assertTrue;

final class UploadPrimitivesTest extends Unit
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

    // ---- SupportedFileTypes ------------------------------------------------

    public function testSupportedTypesMapMimeToExtensionAndKind(): void
    {
        assertTrue(SupportedFileTypes::isSupported('application/pdf'));
        assertSame('pdf', SupportedFileTypes::extensionFor('application/pdf'));
        assertSame(DocumentKind::Pdf, SupportedFileTypes::kindFor('application/pdf'));
        assertSame(DocumentKind::Image, SupportedFileTypes::kindFor('image/webp'));
    }

    public function testUnsupportedTypeReturnsNulls(): void
    {
        assertFalse(SupportedFileTypes::isSupported('text/plain'));
        assertNull(SupportedFileTypes::extensionFor('text/plain'));
        assertNull(SupportedFileTypes::kindFor('text/plain'));
    }

    // ---- SafeFilenameGenerator --------------------------------------------

    public function testTokenIs32HexChars(): void
    {
        assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (new SafeFilenameGenerator())->token());
    }

    public function testTokensAreUnique(): void
    {
        $generator = new SafeFilenameGenerator();

        assertNotSame($generator->token(), $generator->token());
    }

    /**
     * The stored name is token + extension only — no part of the user's filename, so traversal and
     * double-extension tricks are structurally impossible.
     */
    public function testFilenameIsTokenPlusExtension(): void
    {
        $generator = new SafeFilenameGenerator();
        $name = $generator->filename('abcdef0123456789abcdef0123456789', 'pdf');

        assertSame('abcdef0123456789abcdef0123456789.pdf', $name);
        assertStringEndsWith('.pdf', $name);
    }

    // ---- MimeTypeDetector --------------------------------------------------

    public function testDetectsRealTypesFromContent(): void
    {
        $detector = new MimeTypeDetector();

        assertSame('application/pdf', $detector->detect($this->fixtures->pdf()));
        assertSame('image/png', $detector->detect($this->fixtures->png()));
    }

    // ---- ImageInspector ----------------------------------------------------

    public function testInspectReturnsDimensionsForARealImage(): void
    {
        $dimensions = (new ImageInspector())->inspect($this->fixtures->png());

        assertSame(['width' => 1, 'height' => 1], $dimensions);
    }

    public function testInspectReturnsNullForANonImage(): void
    {
        assertNull((new ImageInspector())->inspect($this->fixtures->pdf()));
    }

    public function testInspectReturnsNullForCorruptImageBytes(): void
    {
        assertNull((new ImageInspector())->inspect($this->fixtures->corruptImage()));
    }
}
