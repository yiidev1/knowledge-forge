<?php

declare(strict_types=1);

namespace App\AudioToText\Application\Settings;

use function array_filter;
use function array_map;
use function array_values;
use function explode;
use function trim;

/**
 * Worker liveness and admission-control tunables.
 *
 * Separate from {@see AudioToTextSettings} because these describe the *machine* the worker runs on
 * rather than the transcription itself: they are the settings an operator retunes after moving the
 * application to different hardware, without touching anything about how audio is processed.
 */
final readonly class WorkerSettings
{
    /**
     * @param string $foreignLocks comma-separated lock files belonging to other projects that also run
     *                             whisper on this machine
     */
    public function __construct(
        public int $heartbeatSeconds,
        public int $staleAfterSeconds,
        public int $tickStaleAfterSeconds,
        public int $minAvailableMegabytes,
        public float $maxLoadPerCore,
        public string $foreignLocks,
        public bool $yieldToOtherWhisper,
    ) {}

    /**
     * @return list<string>
     */
    public function foreignLockPaths(): array
    {
        if (trim($this->foreignLocks) === '') {
            return [];
        }

        $paths = array_map(trim(...), explode(',', $this->foreignLocks));

        $paths = array_values(array_filter($paths, static fn(string $path): bool => $path !== ''));

        // Fixed ordering is hygiene rather than necessity — no deadlock is actually reachable, because
        // the other projects never take *our* lock — but a stable acquisition order costs nothing and
        // keeps that true if a second participant is ever added.
        sort($paths);

        return $paths;
    }
}
