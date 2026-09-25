<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

use App\Shared\Machine\MachineResourceProbeInterface;

/**
 * The machine's headroom, plus the one question only this module needs answered.
 *
 * Memory and load are machine-wide facts and live in {@see MachineResourceProbeInterface}, which a second
 * background worker also reads. Extending it rather than restating it means there is one `/proc` parser on
 * this server, and that transcription admission keeps seeing exactly the numbers it saw before.
 *
 * `foreignWhisperRunning()` stays here, and that is the whole reason this interface still exists: it is a
 * question about whisper, not about machines, and nothing outside Audio-to-Text has any business asking it.
 *
 * Implementations must throw rather than guess. An implementation that returned a plausible-looking
 * default when it could not read `/proc` would defeat the fail-closed policy in
 * {@see \App\AudioToText\Application\WorkerAdmissionGuard}, which exists precisely so that an
 * unmeasurable machine is never handed an 834 MB job.
 */
interface SystemResourceProbeInterface extends MachineResourceProbeInterface
{
    /**
     * Best-effort check for a transcription belonging to some other project on this machine.
     *
     * Inherently racy — two processes can both look, both see nothing, and both start. It is defence in
     * depth for a foreign worker started by hand, never the basis of an exclusivity claim; the race-safe
     * mechanism is {@see \App\AudioToText\Application\ForeignLockGuard}.
     */
    public function foreignWhisperRunning(): bool;
}
