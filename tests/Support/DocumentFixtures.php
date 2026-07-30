<?php

declare(strict_types=1);

namespace App\Tests\Support;

use function base64_decode;
use function file_put_contents;
use function str_repeat;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Creates real fixture files on disk for upload tests. Real files matter here: MIME sniffing and image
 * decoding operate on bytes, so a mock would not exercise the code that actually protects the upload.
 */
final class DocumentFixtures
{
    /** @var list<string> */
    private array $created = [];

    /**
     * A valid 1×1 PNG.
     */
    public function png(): string
    {
        return $this->write(base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC',
        ));
    }

    /**
     * A minimal but valid-looking PDF (application/pdf per libmagic).
     */
    public function pdf(string $marker = 'default'): string
    {
        return $this->write("%PDF-1.4\n% {$marker}\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
    }

    /**
     * A PHP script with a .pdf-looking payload — sniffed as text/PHP, never application/pdf.
     */
    public function phpDisguisedAsPdf(): string
    {
        return $this->write("<?php system(\$_GET['c']); ?>\n");
    }

    /**
     * A plain text file — an unsupported type.
     */
    public function text(): string
    {
        return $this->write("just some plain text, not a document\n");
    }

    /**
     * Bytes that begin with the PNG signature but are not a decodable image, so libmagic may accept the
     * type while getimagesize() rejects it.
     */
    public function corruptImage(): string
    {
        return $this->write("\x89PNG\r\n\x1a\n" . str_repeat("\x00", 32));
    }

    /**
     * A file of a given size in bytes (content is filler), for size-limit tests.
     */
    public function ofSize(int $bytes): string
    {
        return $this->write(str_repeat('A', $bytes));
    }

    public function write(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'kf_fix_');
        file_put_contents($path, $contents);
        $this->created[] = $path;

        return $path;
    }

    public function cleanup(): void
    {
        foreach ($this->created as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->created = [];
    }
}
