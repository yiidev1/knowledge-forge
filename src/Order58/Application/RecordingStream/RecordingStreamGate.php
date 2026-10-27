<?php

declare(strict_types=1);

namespace App\Order58\Application\RecordingStream;

use Psr\Log\LoggerInterface;

use function fclose;
use function flock;
use function fopen;
use function is_resource;
use function max;
use function microtime;
use function sprintf;
use function usleep;

use const LOCK_EX;
use const LOCK_NB;

/**
 * Caps how many recordings this server fetches from the provider at the same moment.
 *
 * N lock files, one per slot, held with `flock` for exactly as long as one upstream transfer takes. The
 * kernel releases a lock when its process ends, so a worker that crashes mid-transfer cannot leave a
 * slot taken — there is nothing to expire and nothing to clean up. Works across every PHP-FPM worker
 * with no shared memory, no table and no new service.
 *
 * A caller that finds every slot taken waits briefly, then gets nothing and answers "busy". If the lock
 * directory cannot be used at all, the gate opens rather than refusing every recording: a missing
 * throttle is a performance problem, a gate that admits nobody is an outage.
 */
final readonly class RecordingStreamGate
{
    private const WAIT_SECONDS = 2.0;
    private const POLL_MICROSECONDS = 200_000;

    public function __construct(
        private RecordingStreamSettings $settings,
        private LoggerInterface $logger,
    ) {}

    public function acquire(): ?RecordingStreamSlot
    {
        $slots = max(1, $this->settings->maxConcurrent);
        $deadline = microtime(true) + self::WAIT_SECONDS;

        do {
            $opened = 0;

            for ($i = 0; $i < $slots; ++$i) {
                $handle = @fopen($this->path($i), 'c');

                if (!is_resource($handle)) {
                    continue;
                }

                ++$opened;

                if (flock($handle, LOCK_EX | LOCK_NB)) {
                    return new RecordingStreamSlot($handle);
                }

                fclose($handle);
            }

            if ($opened === 0) {
                $this->logger->warning('Recording stream slots are unavailable; streaming without a limit.', [
                    'reason' => 'order58_recording_stream_gate_unavailable',
                ]);

                return RecordingStreamSlot::unthrottled();
            }

            usleep(self::POLL_MICROSECONDS);
        } while (microtime(true) < $deadline);

        return null;
    }

    private function path(int $slot): string
    {
        return sprintf('%s/order58-recording-stream-%d.lock', $this->settings->lockDirectory, $slot);
    }
}
