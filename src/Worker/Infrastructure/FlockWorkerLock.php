<?php

declare(strict_types=1);

namespace App\Worker\Infrastructure;

use App\Shared\Infrastructure\Storage\StoragePaths;
use App\Worker\Application\WorkerLockInterface;

use function dirname;
use function flock;
use function fopen;
use function is_dir;
use function mkdir;

use const LOCK_EX;
use const LOCK_NB;
use const LOCK_UN;

/**
 * A `flock`-based worker lock.
 *
 * Belt-and-braces with the cron-level `flock` in the crontab entry: this guards a manual
 * `./yii kf:worker:run` from colliding with a scheduled run too. The exclusive, non-blocking lock is
 * held for the life of the open handle; the OS releases it if the process dies, so a crash never leaves
 * a stuck lock.
 */
final class FlockWorkerLock implements WorkerLockInterface
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(
        private readonly StoragePaths $paths,
    ) {}

    public function acquire(): bool
    {
        $path = $this->paths->lockFile;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $handle = @fopen($path, 'c');
        if ($handle === false) {
            return false;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        $this->handle = $handle;

        return true;
    }

    public function release(): void
    {
        if ($this->handle !== null) {
            // Move to a local and null the property first, so the property never holds a closed handle.
            $handle = $this->handle;
            $this->handle = null;
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
