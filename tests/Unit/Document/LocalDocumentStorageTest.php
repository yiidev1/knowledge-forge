<?php

declare(strict_types=1);

namespace App\Tests\Unit\Document;

use App\Document\Application\Storage\StoragePathException;
use App\Document\Infrastructure\LocalDocumentStorage;
use App\Shared\Infrastructure\Storage\StoragePaths;
use Codeception\Test\Unit;

use function is_dir;
use function scandir;
use function str_starts_with;
use function sys_get_temp_dir;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertFileDoesNotExist;
use function PHPUnit\Framework\assertFileExists;
use function PHPUnit\Framework\assertStringStartsWith;
use function PHPUnit\Framework\assertTrue;

final class LocalDocumentStorageTest extends Unit
{
    private string $root;
    private LocalDocumentStorage $storage;

    protected function _before(): void
    {
        $this->root = sys_get_temp_dir() . '/kf_stor_' . bin2hex(random_bytes(6));
        $this->storage = new LocalDocumentStorage(
            new StoragePaths($this->root, $this->root . '/worker.lock', $this->root . '/logs'),
        );
    }

    protected function _after(): void
    {
        $this->removeDir($this->root);
    }

    public function testMoveIntoPlacesFileUnderKnowledgeBaseDirectory(): void
    {
        $temp = $this->storage->createTemporaryFile();
        file_put_contents($temp, 'data');

        $relative = $this->storage->moveInto($temp, 42, 'abc.pdf');

        assertStringStartsWith('knowledge-bases/42/documents/', $relative);
        assertFileExists($this->root . '/' . $relative);
        assertFileDoesNotExist($temp, 'the temporary file is consumed by the move');
    }

    public function testExistsAndDelete(): void
    {
        $temp = $this->storage->createTemporaryFile();
        file_put_contents($temp, 'data');
        $relative = $this->storage->moveInto($temp, 1, 'x.pdf');

        assertTrue($this->storage->exists($relative));
        $this->storage->delete($relative);
        assertFalse($this->storage->exists($relative));
    }

    public function testDiscardRemovesATemporaryFile(): void
    {
        $temp = $this->storage->createTemporaryFile();
        assertFileExists($temp);

        $this->storage->discard($temp);
        assertFileDoesNotExist($temp);
    }

    /**
     * A stored path containing a traversal sequence must be refused, even though the application only
     * ever generates safe paths — defence against a corrupted database value.
     */
    public function testAbsolutePathRejectsTraversal(): void
    {
        $this->expectException(StoragePathException::class);

        $this->storage->absolutePath('knowledge-bases/1/../../../../etc/passwd');
    }

    public function testAbsolutePathRejectsNullByte(): void
    {
        $this->expectException(StoragePathException::class);

        $this->storage->absolutePath("knowledge-bases/1/x\0.pdf");
    }

    public function testResolvedPathStaysWithinRoot(): void
    {
        $absolute = $this->storage->absolutePath('knowledge-bases/1/documents/file.pdf');

        assertTrue(str_starts_with($absolute, $this->root . '/'));
    }

    /**
     * A generated name is trusted, but a separator sneaking into it must still be refused rather than
     * escaping the knowledge-base directory.
     */
    public function testMoveIntoRejectsAnUnsafeStoredName(): void
    {
        $temp = $this->storage->createTemporaryFile();

        $this->expectException(StoragePathException::class);

        $this->storage->moveInto($temp, 1, '../escape.pdf');
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        /** @var list<string> $entries */
        $entries = scandir($dir) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
