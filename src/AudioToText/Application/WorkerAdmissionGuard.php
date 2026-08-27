<?php

declare(strict_types=1);

namespace App\AudioToText\Application;

use App\AudioToText\Domain\SystemResourceProbeInterface;
use Throwable;

use function sprintf;

/**
 * Decides whether this tick should claim a queued job at all.
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
 */
final readonly class WorkerAdmissionGuard
{
    public function __construct(
        private AudioToTextSettings $settings,
        private SystemResourceProbeInterface $probe,
    ) {}

    public function decide(): AdmissionDecision
    {
        try {
            $available = $this->probe->availableMegabytes();
        } catch (Throwable $e) {
            return AdmissionDecision::defer('available memory could not be read: ' . $e->getMessage());
        }

        if ($available < $this->settings->worker->minAvailableMegabytes) {
            return AdmissionDecision::defer(sprintf(
                'available memory %d MB is below the %d MB required',
                $available,
                $this->settings->worker->minAvailableMegabytes,
            ));
        }

        try {
            $load = $this->probe->loadAveragePerCore();
        } catch (Throwable $e) {
            return AdmissionDecision::defer('system load could not be read: ' . $e->getMessage());
        }

        if ($load > $this->settings->worker->maxLoadPerCore) {
            return AdmissionDecision::defer(sprintf(
                'load per core %.2f exceeds the %.2f threshold',
                $load,
                $this->settings->worker->maxLoadPerCore,
            ));
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
