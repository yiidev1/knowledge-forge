<?php

declare(strict_types=1);

namespace App\AudioToText\Application;

use function fclose;
use function flock;
use function fopen;
use function is_readable;
use function is_resource;
use function sprintf;

use const LOCK_EX;
use const LOCK_NB;
use const LOCK_UN;

/**
 * Race-safe coordination with *other* projects on this machine that also run whisper.
 *
 * The problem it solves is specific. Our own `worker.lock` guarantees one audio job for this
 * application, but it knows nothing about a second application with its own lock and its own cron. Two
 * such workers can each check "is anything running?", each see nothing, and each start whisper in the
 * same instant. A process scan cannot fix that — the gap between looking and starting is exactly where
 * the race lives.
 *
 * The fix is to stop looking and start participating. The other project's cron line already serialises
 * itself with `flock -n <its lock file>`; taking an exclusive non-blocking `flock` on *that same file*
 * puts both workers in one kernel-arbitrated queue. Whoever gets the descriptor lock wins, and the
 * loser's `flock -n` exits 1 with no output. There is no window, because `flock(2)` has no window.
 *
 * Nothing is written to the other project. The file is opened `'r'`, never `'c'`, so it is never
 * created; `flock(2)` on Linux locks any open descriptor regardless of its open mode.
 *
 * **A configured lock that cannot be acquired, opened or read defers the tick.** That includes the file
 * being absent. Failing open here would defeat the entire mechanism at exactly the moment it is needed:
 * "I could not determine whether the other project is mid-job" and "the other project is idle" are not
 * the same statement, and only one of them is safe to act on. Blanking the setting is the supported way
 * to opt out.
 */
final class ForeignLockGuard
{
    /** @var list<resource> */
    private array $handles = [];

    public function __construct(
        private readonly AudioToTextSettings $settings,
    ) {}

    /**
     * Attempts to take every configured foreign lock. All or nothing: a partial acquisition is released
     * before returning, so a failed attempt never leaves another project blocked by a lock we are not
     * actually using.
     *
     * @return string|null null on success, or a short reason suitable for the log and for deriving the
     *                     admin-facing deferral message
     */
    public function acquire(): ?string
    {
        $paths = $this->settings->worker->foreignLockPaths();
        if ($paths === []) {
            return null;
        }

        foreach ($paths as $path) {
            $reason = $this->acquireOne($path);
            if ($reason !== null) {
                $this->release();

                return $reason;
            }
        }

        return null;
    }

    public function release(): void
    {
        foreach ($this->handles as $handle) {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }

        $this->handles = [];
    }

    private function acquireOne(string $path): ?string
    {
        if (!is_readable($path)) {
            return sprintf('the coordination lock "%s" is missing or unreadable', $path);
        }

        $handle = @fopen($path, 'r');
        if (!is_resource($handle)) {
            return sprintf('the coordination lock "%s" could not be opened', $path);
        }

        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return sprintf('the coordination lock "%s" is held by another project', $path);
        }

        $this->handles[] = $handle;

        return null;
    }
}
