<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

/**
 * Reads the machine's current headroom.
 *
 * Behind an interface so the admission tests never touch `/proc` — a test that depends on the real
 * machine's free memory passes or fails according to what else is running, which is no test at all.
 *
 * Implementations must throw rather than guess. An implementation that returns a plausible-looking
 * default when it cannot read `/proc` would defeat the fail-closed policy in
 * {@see \App\AudioToText\Application\WorkerAdmissionGuard}, which exists precisely so that an
 * unmeasurable machine is never handed an 834 MB job.
 */
interface SystemResourceProbeInterface
{
    /**
     * @throws \RuntimeException when the value cannot be read or parsed
     */
    public function availableMegabytes(): int;

    /**
     * @throws \RuntimeException when the value cannot be read or parsed
     */
    public function loadAveragePerCore(): float;

    /**
     * Best-effort check for a transcription belonging to some other project on this machine.
     *
     * Inherently racy — two processes can both look, both see nothing, and both start. It is defence in
     * depth for a foreign worker started by hand, never the basis of an exclusivity claim; the race-safe
     * mechanism is {@see \App\AudioToText\Application\ForeignLockGuard}.
     */
    public function foreignWhisperRunning(): bool;
}
