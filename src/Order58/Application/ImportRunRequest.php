<?php

declare(strict_types=1);

namespace App\Order58\Application;

use Psr\Log\LoggerInterface;
use Throwable;

use function dirname;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function time;

/**
 * Asks the importer to run now, without running anything itself.
 *
 * ## The problem
 *
 * Downloading is scheduled work: rows are written and a timer picks them up. That is the right shape —
 * a web request must not spend minutes fetching megabytes from a third party — but it meant a click
 * could sit saying "Pending download" for a whole timer interval on a server that was doing nothing at
 * all. The work was correct and the product felt broken.
 *
 * ## What this does
 *
 * Writes one small file. That is the entire mechanism.
 *
 * A systemd `.path` unit watches that file and starts the **existing** importer service when it
 * changes, so the run is started by systemd rather than by PHP. Everything the unit declares still
 * applies — `CPUQuota=25%`, `MemoryMax=256M`, `Nice=15`, the hardening, `--once` — and so do the
 * importer's own guards: the flock, {@see \App\Shared\Machine\ResourceAdmission}, and the one-call
 * bound. None of them is weakened, because none of them is involved: this only decides *when* a run is
 * attempted, never *what* it may do.
 *
 * ## Why not spawn the command directly
 *
 * A detached `proc_open()` from the web process would need no server configuration, and would lose
 * every one of those limits: the child inherits PHP-FPM's cgroup, not the unit's. On a server with no
 * swap that trades the exact protection the scheduling exists to provide for a few seconds of latency.
 *
 * ## It is a hint, and failing to send it is not an error
 *
 * If the `.path` unit is not installed, or the directory cannot be written, nothing here complains and
 * the request succeeds. The rows are already saved; the timer will take them exactly as it did before.
 * A download must never fail because an optimisation could not be applied.
 */
final readonly class ImportRunRequest
{
    public function __construct(
        private string $triggerFile,
        private LoggerInterface $logger,
    ) {}

    /** Ask for a run as soon as the machine is willing to give one. Never throws. */
    public function requestRun(): void
    {
        try {
            $directory = dirname($this->triggerFile);

            if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
                return;
            }

            // The contents are irrelevant — a `.path` unit watches for the write, not for what was
            // written. A timestamp is here only so somebody reading the file by hand can see when the
            // last request was made.
            @file_put_contents($this->triggerFile, (string) time() . "\n");
        } catch (Throwable $e) {
            // Deliberately swallowed, and deliberately recorded. The caller has already saved its work
            // and must not be failed for this; but a trigger that has stopped working would otherwise
            // be invisible, and the only symptom would be downloads feeling slow again.
            $this->logger->warning('The Order58 importer could not be asked to run immediately.', [
                'reason' => 'order58_import_trigger_failed',
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
            ]);
        }
    }
}
