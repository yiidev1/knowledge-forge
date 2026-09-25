<?php

declare(strict_types=1);

namespace App\Shared\Machine;

/**
 * Reads the machine's current headroom: how much memory a new allocation could obtain, and how busy the
 * CPUs are.
 *
 * Deliberately generic. Nothing here knows what the work is, which is what lets two unrelated background
 * workers ask the same question with different answers about what counts as "enough" — a transcription
 * needs most of a gigabyte, a recording download needs a few dozen megabytes, and both belong behind the
 * same measurement. What differs between them is the {@see ResourceBudget}, not the probe.
 *
 * Behind an interface so admission tests never touch `/proc`: a test that read this machine's real free
 * memory would pass or fail according to whatever else happened to be running, which is no test at all.
 *
 * **Implementations must throw rather than guess.** An implementation that returned a plausible-looking
 * default when it could not read `/proc` would defeat the fail-closed policy in
 * {@see ResourceAdmission}, which exists precisely so an unmeasurable machine is never handed work.
 */
interface MachineResourceProbeInterface
{
    /**
     * Memory a new allocation could realistically obtain, in megabytes.
     *
     * "Available", not "free" — reclaimable page cache counts. See {@see ProcMachineResourceProbe} for
     * why that distinction decides whether a guard works or stalls forever.
     *
     * @throws \RuntimeException when the value cannot be read or parsed
     */
    public function availableMegabytes(): int;

    /**
     * The one-minute load average divided by the number of logical CPUs.
     *
     * Per core rather than absolute, so one threshold means the same thing on a 2-core VM and a 16-core
     * server and nothing has to be retuned when the hardware changes.
     *
     * @throws \RuntimeException when the value cannot be read or parsed
     */
    public function loadAveragePerCore(): float;
}
