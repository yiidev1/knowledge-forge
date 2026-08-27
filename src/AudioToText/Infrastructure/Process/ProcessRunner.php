<?php

declare(strict_types=1);

namespace App\AudioToText\Infrastructure\Process;

use App\AudioToText\Application\AudioToTextSettings;

use function fclose;
use function feof;
use function fread;
use function is_resource;
use function microtime;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function stream_select;
use function stream_set_blocking;
use function strlen;
use function substr;
use function usleep;

/**
 * Runs an external program safely.
 *
 * Three properties, each of which has a specific failure it prevents:
 *
 * 1. **The command is an array, never a string.** `proc_open()` given a list hands it straight to
 *    `execve()` with no shell in between, so quoting, globbing, `;`, backticks, `$(…)` and embedded
 *    newlines are all inert. There is nothing for `escapeshellarg()` to protect, because there is no
 *    shell to protect it from.
 *
 * 2. **Both pipes are drained without blocking.** A child that fills its stderr buffer while the parent
 *    is blocked reading stdout deadlocks, and neither side ever notices. `stream_select()` over both
 *    descriptors is what makes the wall-clock timeout below actually reachable.
 *
 * The child environment is not built here: it comes from the one Audio-to-Text settings object, so
 * the CPU budget is a single configured number rather than a literal repeated per launcher.
 *
 * 3. **Captured output is capped.** whisper.cpp in a bad mood can emit output faster than anyone wants
 *    to hold in memory, and this runs in a worker that may be under a cgroup memory limit.
 */
final readonly class ProcessRunner
{
    private const READ_CHUNK = 8192;
    private const SELECT_TIMEOUT_MICROSECONDS = 200000;
    private const MAX_CAPTURED_BYTES = 262144;
    private const TERMINATION_GRACE_MICROSECONDS = 2000000;
    private const TERMINATION_POLL_MICROSECONDS = 50000;
    private const SIGTERM = 15;
    private const SIGKILL = 9;

    public function __construct(
        private AudioToTextSettings $settings,
    ) {}

    /**
     * @param list<string> $command executable first, then one argument per element
     */
    public function run(array $command, int $timeoutSeconds): ProcessResult
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes = [];
        $process = @proc_open($command, $descriptors, $pipes, null, $this->settings->childProcessEnvironment());

        if (!is_resource($process)) {
            return new ProcessResult(-1, '', 'proc_open() could not start the process.', false);
        }

        // Close stdin immediately. Nothing is ever written to these children, and a binary that decides
        // to prompt for input gets EOF instead of hanging until the timeout expires.
        if (isset($pipes[0])) {
            fclose($pipes[0]);
        }
        unset($pipes[0]);

        foreach ([1, 2] as $key) {
            if (isset($pipes[$key])) {
                stream_set_blocking($pipes[$key], false);
            }
        }

        $captured = [1 => '', 2 => ''];
        $deadline = microtime(true) + $timeoutSeconds;
        $timedOut = false;

        while ($pipes !== []) {
            if (microtime(true) >= $deadline) {
                $timedOut = true;

                break;
            }

            $read = $pipes;

            if ($read === []) {
                break;
            }

            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, 0, self::SELECT_TIMEOUT_MICROSECONDS);

            if ($ready === false) {
                break;
            }

            foreach ($read as $key => $pipe) {
                $chunk = fread($pipe, self::READ_CHUNK);

                if ($chunk === false || ($chunk === '' && feof($pipe))) {
                    fclose($pipe);
                    unset($pipes[$key]);

                    continue;
                }

                if ($chunk === '') {
                    continue;
                }

                if (strlen($captured[$key]) < self::MAX_CAPTURED_BYTES) {
                    $captured[$key] = substr($captured[$key] . $chunk, 0, self::MAX_CAPTURED_BYTES);
                }
            }
        }

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        if ($timedOut) {
            $this->terminate($process);
        }

        $exitCode = proc_close($process);

        return new ProcessResult($exitCode, $captured[1], $captured[2], $timedOut);
    }

    /**
     * SIGTERM, a grace period, then SIGKILL.
     *
     * The polite signal first is not manners: ffmpeg and whisper.cpp both close their output files on
     * SIGTERM, and a SIGKILL straight away leaves half-written artefacts in the workspace for the next
     * stage to misread.
     *
     * @param resource $process
     */
    private function terminate($process): void
    {
        @proc_terminate($process, self::SIGTERM);

        $waited = 0;
        while ($waited < self::TERMINATION_GRACE_MICROSECONDS) {
            if (@proc_get_status($process)['running'] !== true) {
                return;
            }

            usleep(self::TERMINATION_POLL_MICROSECONDS);
            $waited += self::TERMINATION_POLL_MICROSECONDS;
        }

        @proc_terminate($process, self::SIGKILL);
    }
}
