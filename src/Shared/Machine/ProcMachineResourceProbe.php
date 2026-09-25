<?php

declare(strict_types=1);

namespace App\Shared\Machine;

use RuntimeException;

use function file_get_contents;
use function is_numeric;
use function max;
use function preg_match;
use function preg_match_all;
use function preg_split;
use function trim;

/**
 * Reads machine headroom from `/proc`, which is free, synchronous and needs no privileges.
 *
 * Every method throws rather than guessing. That is the contract the fail-closed admission policy depends
 * on: a probe that quietly returned "plenty of memory" when it could not read `/proc/meminfo` would turn
 * a safety mechanism into a decoration.
 *
 * Lifted verbatim out of the Audio-to-Text module when a second worker needed the same two numbers. The
 * parsing is unchanged, so the values a transcription tick sees are exactly the values it saw before.
 */
final readonly class ProcMachineResourceProbe implements MachineResourceProbeInterface
{
    /**
     * `MemAvailable`, not `MemFree`.
     *
     * The distinction is the difference between a working guard and a permanently stalled queue. On a
     * machine with several gigabytes sitting in page cache, `MemFree` reports a few hundred megabytes and
     * would defer every job forever, while the kernel's own estimate of what a new allocation could
     * actually obtain is several gigabytes. On a small server with no swap the estimate matters even
     * more, because there is nowhere for a wrong guess to spill.
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
