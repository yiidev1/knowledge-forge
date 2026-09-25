<?php

declare(strict_types=1);

namespace App\AudioToText\Infrastructure;

use App\AudioToText\Domain\SystemResourceProbeInterface;
use App\AudioToText\Infrastructure\Process\ProcessRunner;
use App\Shared\Machine\MachineResourceProbeInterface;
use RuntimeException;

/**
 * This module's probe: the shared machine reading, plus the whisper process scan.
 *
 * The `/proc/meminfo` and `/proc/loadavg` parsing used to live here and now lives in
 * {@see \App\Shared\Machine\ProcMachineResourceProbe}, because a second background worker needs the same
 * two numbers and two copies of that parsing would eventually disagree. The values are unchanged and the
 * delegation is unconditional, so transcription admission behaves exactly as it did before.
 *
 * What stays is `foreignWhisperRunning()`. It is specific to one binary belonging to one pipeline, so it
 * would be wrong in `Shared` — a worker that runs no whisper has no reason to ask, and no reason to wait.
 */
final readonly class ProcSystemResourceProbe implements SystemResourceProbeInterface
{
    private const PGREP_TIMEOUT_SECONDS = 5;

    public function __construct(
        private ProcessRunner $processes,
        private MachineResourceProbeInterface $machine,
    ) {}

    public function availableMegabytes(): int
    {
        return $this->machine->availableMegabytes();
    }

    public function loadAveragePerCore(): float
    {
        return $this->machine->loadAveragePerCore();
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
}
