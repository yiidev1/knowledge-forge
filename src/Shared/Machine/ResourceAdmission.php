<?php

declare(strict_types=1);

namespace App\Shared\Machine;

use Throwable;

use function sprintf;

/**
 * The two machine-wide questions every background worker on this server should ask before it starts:
 * is there memory, and is the machine already busy?
 *
 * ## It is checked before the work is claimed, never after
 *
 * A caller that defers must leave its queue item exactly as it found it — not claimed, not failed, not
 * retried, no attempt consumed. The queue simply waits for a tick that can afford it. That is a property
 * of how callers use this class, and each of them documents it at the call site.
 *
 * ## It fails closed
 *
 * If the probe cannot read the machine's memory or load, the tick defers. A resource guard that admits
 * work when it cannot measure resources is not a guard. The cost of failing closed is a stalled queue,
 * which is why every caller logs its deferral with the reason — a stall that explains itself is a support
 * question, a silent one is an outage.
 *
 * ## What it deliberately does not know
 *
 * Nothing about whisper, transcription, downloads or any other specific work. Those belong to the caller,
 * which is what allows this to sit in `Shared` and be reached from two modules that may not name each
 * other. A check that is about one tool — is a foreign `whisper-cli` running, is another project's lock
 * held — stays with that tool.
 */
final readonly class ResourceAdmission
{
    public function __construct(
        private MachineResourceProbeInterface $probe,
    ) {}

    /**
     * Memory first, then load. The order is not arbitrary: on a server with no swap, memory is the
     * constraint that kills a process, while load only makes it slow.
     */
    public function decide(ResourceBudget $budget): AdmissionDecision
    {
        try {
            $available = $this->probe->availableMegabytes();
        } catch (Throwable $e) {
            return AdmissionDecision::defer('available memory could not be read: ' . $e->getMessage());
        }

        if ($available < $budget->minAvailableMegabytes) {
            return AdmissionDecision::defer(sprintf(
                'available memory %d MB is below the %d MB required',
                $available,
                $budget->minAvailableMegabytes,
            ));
        }

        try {
            $load = $this->probe->loadAveragePerCore();
        } catch (Throwable $e) {
            return AdmissionDecision::defer('system load could not be read: ' . $e->getMessage());
        }

        if ($load > $budget->maxLoadPerCore) {
            return AdmissionDecision::defer(sprintf(
                'load per core %.2f exceeds the %.2f threshold',
                $load,
                $budget->maxLoadPerCore,
            ));
        }

        return AdmissionDecision::admit();
    }
}
