<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Storage;

use App\Shared\Domain\Exception\ConfigurationException;

use function rtrim;
use function str_starts_with;

/**
 * Absolute, alias-resolved filesystem locations the application writes to.
 *
 * Paths arrive here already resolved (`@runtime/storage` → `/var/www/.../runtime/storage`) so that no
 * component further down has to know about aliases, and so a relative path can never be interpreted
 * against whatever working directory cron happens to use.
 */
final readonly class StoragePaths
{
    public function __construct(
        public string $documentRoot,
        public string $lockFile,
        public string $logDirectory,
    ) {
        foreach (['KNOWLEDGE_STORAGE_PATH' => $documentRoot, 'DOCUMENT_WORKER_LOCK_PATH' => $lockFile] as $var => $path) {
            if ($path === '') {
                throw ConfigurationException::missing($var, 'locate application storage');
            }

            if (!str_starts_with($path, '/')) {
                throw ConfigurationException::invalid($var, 'an absolute path, or an alias such as @runtime/storage');
            }
        }
    }

    /**
     * Per-knowledge-base directory holding original uploads. Never inside `public/`.
     */
    public function knowledgeBaseDirectory(int $knowledgeBaseId): string
    {
        return rtrim($this->documentRoot, '/') . '/knowledge-bases/' . $knowledgeBaseId;
    }

    public function lockDirectory(): string
    {
        return dirname($this->lockFile);
    }
}
