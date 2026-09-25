<?php

declare(strict_types=1);

namespace App\AudioToText\Application;

use App\AudioToText\Domain\SystemResourceProbeInterface;
use App\Shared\Machine\AdmissionDecision;
use App\Shared\Machine\ResourceAdmission;
use App\Shared\Machine\ResourceBudget;
use Throwable;

/**
 * Decides whether this tick should claim a queued transcription at all.
 *
 * Checked *before* the claim, never after: a job that is not admitted stays QUEUED and untouched. It is
 * not failed, not retried and not counted against anything — the queue simply waits for a tick that can
 * afford it.
 *
 * **The guard fails closed.** If the probe cannot read the machine's memory or load, the tick defers.
 * A resource guard that admits work when it cannot measure resources is not a guard; and on this
 * hardware the job it would be waving through peaks at 834 MB. The cost of failing closed is a stalled
 * queue, which is why every deferral is logged with its reason and surfaced on the admin page rather
 * than being silent — a stall that explains itself is a support question, a silent one is an outage.
 *
 * Thresholds are deliberately generous. This exists for the pathological case, not to ration normal
 * use: on an idle machine every check passes with room to spare.
 *
 * ## Two layers, one order
 *
 * The memory and load questions are machine-wide and are answered by {@see ResourceAdmission} against
 * this module's own {@see ResourceBudget} — the same mechanism a second, much lighter worker uses with a
 * much smaller budget. The whisper process scan stays here because it is about whisper, and it runs last
 * because it is the least reliable of the three.
 */
final readonly class WorkerAdmissionGuard
{
    public function __construct(
        private AudioToTextSettings $settings,
        private SystemResourceProbeInterface $probe,
    ) {}

    public function decide(): AdmissionDecision
    {
        $machine = (new ResourceAdmission($this->probe))->decide(new ResourceBudget(
            $this->settings->worker->minAvailableMegabytes,
            $this->settings->worker->maxLoadPerCore,
        ));

        if (!$machine->admitted) {
            return $machine;
        }

        // Best-effort, and labelled as such wherever it appears. It catches a foreign worker started by
        // hand, which takes no cron lock and is therefore invisible to ForeignLockGuard. It is racy by
        // construction and is never the basis of an exclusivity claim.
        if ($this->settings->worker->yieldToOtherWhisper) {
            try {
                if ($this->probe->foreignWhisperRunning()) {
                    return AdmissionDecision::defer('another whisper process is already running on this machine');
                }
            } catch (Throwable $e) {
                return AdmissionDecision::defer('foreign process check failed: ' . $e->getMessage());
            }
        }

        return AdmissionDecision::admit();
    }
}
