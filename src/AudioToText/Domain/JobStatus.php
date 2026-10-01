<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

/**
 * The stable states of a transcription job.
 *
 * These are the contract: the worker's claim query, the queue limits and the ownership-free page guard
 * all key off them, and they are what the status endpoint reports. {@see ProcessingStage} carries the
 * finer-grained "what is it doing right now" detail and is deliberately kept separate, so adding a
 * pipeline step never changes the meaning of a status.
 *
 * ## Why there is a state before the queue
 *
 * A recording can now be acquired without anybody asking for a transcript — the operator wants to hear
 * the call first, and a transcript costs CPU or money. That recording still needs a row, because the row
 * is where its audio, duration, store, order and channel live; what it does not need is a worker.
 *
 * {@see NOT_REQUESTED} is that state, and it is defined by what it is **not** part of. It appears in
 * neither {@see activeValues()} nor {@see terminalValues()}, which is not an oversight: those two lists
 * are what the claim query, the queue limit, the queue position, the orphan sweep's protected set and
 * the retention purge are all built from, so a status outside both is invisible to every one of them
 * without a line of those queries changing.
 *
 * It is not a fake job. There is no transcript, no segment, no confidence and no provider commitment —
 * the provider is chosen when somebody actually asks, which may be a different one by then.
 */
enum JobStatus: string
{
    /**
     * Audio is stored and nobody has asked for a transcript.
     *
     * Reached only by a download that was explicitly for the recording rather than for the text. Leaves
     * this state exactly once, when a person presses the button — never on a timer, never in bulk.
     */
    case NOT_REQUESTED = 'NOT_REQUESTED';

    case QUEUED = 'QUEUED';
    case PROCESSING = 'PROCESSING';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';

    /**
     * The statuses that occupy a queue slot. A job in one of these is claimable or already claimed;
     * anything else is terminal and no longer counts against either limit.
     *
     * @return non-empty-list<string>
     */
    public static function activeValues(): array
    {
        return [self::QUEUED->value, self::PROCESSING->value];
    }

    /**
     * @return non-empty-list<string>
     */
    public static function terminalValues(): array
    {
        return [self::COMPLETED->value, self::FAILED->value];
    }

    public function isActive(): bool
    {
        return $this === self::QUEUED || $this === self::PROCESSING;
    }

    /**
     * What to call this on screen — in the operator's words, never the mechanism's.
     *
     * "Queued" named a queue the reader has no way to see and cannot act on. These say what is true of
     * their recording instead, and the same five words are used on every screen that reports one.
     */
    public function label(): string
    {
        return match ($this) {
            self::NOT_REQUESTED => 'Ready for transcription',
            self::QUEUED => 'Transcription requested',
            self::PROCESSING => 'Transcribing',
            self::COMPLETED => 'Completed',
            self::FAILED => 'Failed',
        };
    }

    /** Whether a transcript has been asked for at all. False only before somebody pressed the button. */
    public function transcriptionRequested(): bool
    {
        return $this !== self::NOT_REQUESTED;
    }

    /**
     * Unknown or malformed stored values read as FAILED rather than throwing in a template.
     *
     * Fail closed: an unrecognised status is a row nothing can act on, and showing it as waiting would
     * promise work that will never happen.
     */
    public static function fromStorage(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::FAILED;
    }
}
