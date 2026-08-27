<?php

declare(strict_types=1);

namespace App\AudioToText\Infrastructure;

use App\AudioToText\Domain\SystemResourceProbeInterface;
use App\AudioToText\Infrastructure\Process\ProcessRunner;
use RuntimeException;

use function file_get_contents;
use function is_numeric;
use function max;
use function preg_match;
use function trim;

/**
 * Reads machine headroom from `/proc`, which is free, synchronous and needs no privileges.
 *
 * Every method throws rather than guessing. That is the contract the fail-closed admission policy
 * depends on: a probe that quietly returned "plenty of memory" when it could not read `/proc/meminfo`
 * would turn a safety mechanism into a decoration.
 */
final readonly class ProcSystemResourceProbe implements SystemResourceProbeInterface
{
    private const PGREP_TIMEOUT_SECONDS = 5;

    public function __construct(
        private ProcessRunner $processes,
    ) {}

    /**
     * `MemAvailable`, not `MemFree`.
     *
     * The distinction matters on a machine like this one, where 6.6 GB sits in page cache: `MemFree`
     * would report a few hundred megabytes and defer every job forever, while the kernel's own estimate
     * of what a new allocation could actually obtain is several gigabytes.
     */
    public function availableMegabytes(): int
    {
        $contents = @file_get_contents('/proc/meminfo');
        if ($contents === false) {
            throw new RuntimeException('/proc/meminfo could not be read');
        }

        if (preg_match('/^MemAvailable:\s+(\d+)\s+kB$/m', $contents, $matches) !== 1) {
            throw new RuntimeException('/proc/meminfo contained no MemAvailable line');
        }

        return (int) ((int) $matches[1] / 1024);
    }

    public function loadAveragePerCore(): float
    {
        $contents = @file_get_contents('/proc/loadavg');
        if ($contents === false) {
            throw new RuntimeException('/proc/loadavg could not be read');
        }

        $parts = preg_split('/\s+/', trim($contents));
        if ($parts === false || !isset($parts[0]) || !is_numeric($parts[0])) {
            throw new RuntimeException('/proc/loadavg could not be parsed');
        }

        return (float) $parts[0] / (float) $this->coreCount();
    }

    /**
     * Best-effort, and racy by construction — see the interface docblock.
     *
     * A `pgrep` failure is not an error here: exit code 1 is simply "no match", which is the common and
     * expected case. Only a genuinely unusable result throws, so that the fail-closed policy applies to
     * "the check broke" rather than to "the check found nothing".
     */
    public function foreignWhisperRunning(): bool
    {
        $result = $this->processes->run(['/usr/bin/pgrep', '-x', 'whisper-cli'], self::PGREP_TIMEOUT_SECONDS);

        if ($result->timedOut) {
            throw new RuntimeException('pgrep timed out');
        }

        // 0 = at least one match, 1 = no match. Anything else means pgrep itself could not answer.
        return match ($result->exitCode) {
            0 => true,
            1 => false,
            default => throw new RuntimeException('pgrep returned exit code ' . $result->exitCode),
        };
    }

    /**
     * Logical CPUs. `nproc` is not used: it means shelling out on every tick for a number that is
     * sitting in a file, and `/proc/cpuinfo` is available even where coreutils is not.
     */
    private function coreCount(): int
    {
        $contents = @file_get_contents('/proc/cpuinfo');
        if ($contents === false) {
            return 1;
        }

        $count = preg_match_all('/^processor\s*:/m', $contents);

        return max(1, (int) $count);
    }
}
