<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Document;

use App\Document\Application\Storage\DocumentStorageInterface;
use BadMethodCallException;
use Psr\Http\Message\StreamInterface;

/**
 * In-memory document storage for tests. Only the derived-markdown write path is modelled (used by the
 * Order58 generated-document sync); the upload/streaming methods throw if a test unexpectedly reaches them.
 */
final class InMemoryDocumentStorage implements DocumentStorageInterface
{
    /** @var array<string, string> relative path => contents */
    public array $written = [];

    public function putContents(string $relativePath, string $contents): string
    {
        $this->written[$relativePath] = $contents;

        return $relativePath;
    }

    public function derivedMarkdownPath(int $knowledgeBaseId, string $storageToken): string
    {
        return 'kb' . $knowledgeBaseId . '/' . $storageToken . '.md';
    }

    public function delete(string $relativePath): void
    {
        unset($this->written[$relativePath]);
    }

    public function exists(string $relativePath): bool
    {
        return isset($this->written[$relativePath]);
    }

    public function absolutePath(string $relativePath): string
    {
        return '/fake/' . $relativePath;
    }

    public function createTemporaryFile(): string
    {
        throw self::unused();
    }

    public function moveInto(string $temporaryAbsolutePath, int $knowledgeBaseId, string $storedFilename): string
    {
        throw self::unused();
    }

    public function discard(string $temporaryAbsolutePath): void
    {
        throw self::unused();
    }

    public function readStream(string $relativePath): StreamInterface
    {
        throw self::unused();
    }

    private static function unused(): BadMethodCallException
    {
        return new BadMethodCallException('Not used by the Order58 sync safeguard tests.');
    }
}
