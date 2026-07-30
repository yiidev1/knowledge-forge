<?php

declare(strict_types=1);

namespace App\Ai\Infrastructure\Usage;

use App\Ai\Application\Usage\UsageSnapshot;
use App\Ai\Application\Usage\UsageSnapshotStoreInterface;
use JsonException;

use function bin2hex;
use function chmod;
use function dirname;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function flock;
use function fopen;
use function is_array;
use function is_dir;
use function json_decode;
use function json_encode;
use function mkdir;
use function random_bytes;
use function rename;
use function unlink;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const LOCK_EX;
use const LOCK_SH;
use const LOCK_UN;

/**
 * Stores the usage snapshot as one JSON file, written atomically under a stable lock.
 *
 * Two details that are easy to get wrong and both matter here:
 *
 * 1. **The lock is a separate, fixed file.** Locking the temporary file would be useless — each write
 *    creates a new inode, so two concurrent syncs would each lock their own file and happily race on
 *    the rename. One stable lock path is what actually serialises them.
 * 2. **The write is temp-then-rename, with the lock held across both.** `rename()` within a filesystem
 *    is atomic, so a reader either sees the whole previous snapshot or the whole new one, never a
 *    half-written file. Writing in place would let a reader observe a truncated JSON document.
 *
 * A failed write leaves the previous snapshot untouched: the temporary file is removed and nothing is
 * renamed over the live one. That is what lets the page keep showing the last good data when a sync
 * fails.
 *
 * Mode 0664 with the setgid group on `runtime/cache` keeps the file writable by the web user that
 * created it and readable by the checkout owner.
 */
final readonly class FileUsageSnapshotStore implements UsageSnapshotStoreInterface
{
    private const FILE_MODE = 0664;

    public function __construct(
        private string $path,
        private string $lockPath,
    ) {}

    public function latest(): ?UsageSnapshot
    {
        $handle = @fopen($this->lockPath, 'c');
        if ($handle === false) {
            return null;
        }

        try {
            // A shared lock: many readers may hold it at once, but never while a write holds it
            // exclusively, so a reader can not observe the moment of the rename.
            if (!flock($handle, LOCK_SH)) {
                return null;
            }

            $raw = @file_get_contents($this->path);
            if ($raw === false || $raw === '') {
                return null;
            }

            /** @var mixed $decoded */
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? UsageSnapshot::fromArray($decoded) : null;
        } catch (JsonException) {
            // A corrupt cache is "not synced yet", not a 500.
            return null;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function save(UsageSnapshot $snapshot): void
    {
        $this->ensureDirectory(dirname($this->path));

        $handle = @fopen($this->lockPath, 'c');
        if ($handle === false) {
            throw new UsageSnapshotWriteFailed('Could not open the usage snapshot lock file.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new UsageSnapshotWriteFailed('Could not acquire the usage snapshot lock.');
            }

            @chmod($this->lockPath, self::FILE_MODE);

            $json = json_encode($snapshot->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

            // Same directory as the target, so the rename stays within one filesystem and therefore
            // stays atomic.
            $temporary = $this->path . '.' . bin2hex(random_bytes(8)) . '.tmp';

            if (@file_put_contents($temporary, $json) === false) {
                throw new UsageSnapshotWriteFailed('Could not write the usage snapshot.');
            }

            @chmod($temporary, self::FILE_MODE);

            if (!@rename($temporary, $this->path)) {
                @unlink($temporary);

                throw new UsageSnapshotWriteFailed('Could not replace the usage snapshot.');
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new UsageSnapshotWriteFailed('Could not create the usage snapshot directory.');
        }
    }
}
