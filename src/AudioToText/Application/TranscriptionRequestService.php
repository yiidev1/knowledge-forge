<?php

declare(strict_types=1);

namespace App\AudioToText\Application;

use App\AudioToText\Domain\AudioToTextSettingsRepositoryInterface;
use App\AudioToText\Domain\TranscriptionJobRepositoryInterface;

/**
 * Asks for the transcript of one recording that was acquired without one.
 *
 * ## One recording, never a call
 *
 * It takes a single recording's public id and touches that row alone. A call can arrive as a mixed
 * recording plus a caller side plus a callee side, and those are three separate recordings of the same
 * conversation — the mixed one is usually all anybody needs, and transcribing all three by default would
 * triple the cost of every press for a transcript nobody read. Asking for one leaves the other two
 * exactly as they were, available to be asked for later or never.
 *
 * ## The provider is chosen now, not when the audio arrived
 *
 * A recording downloaded last week was downloaded without any transcript in mind, so whatever this server
 * was set to then was a decision about nothing. The engine is picked at the moment somebody actually
 * wants the text, and from that moment the job carries it — the worker never re-reads the default, so
 * changing it later cannot re-route work already asked for.
 *
 * ## Pressing twice costs nothing
 *
 * The whole operation is one conditional UPDATE and its affected-row count is the answer. A double click,
 * a resubmitted form and a retried request all find the row has already left NOT_REQUESTED and change
 * nothing. There is no read-then-write for a second request to slip between.
 *
 * ## It starts nothing
 *
 * No transcription runs here. This moves a row into the state the existing worker claims from, and that
 * worker — on its own schedule, under its own lock, one recording at a time — does the work. Which is
 * the same thing an upload has always done.
 */
final readonly class TranscriptionRequestService
{
    public function __construct(
        private TranscriptionJobRepositoryInterface $jobs,
        private AudioToTextSettingsRepositoryInterface $settings,
    ) {}

    /**
     * @return bool whether this call is the one that requested it; false if it had been already
     */
    public function request(int $jobId): bool
    {
        return $this->jobs->requestTranscription($jobId, $this->settings->defaultProvider());
    }
}
