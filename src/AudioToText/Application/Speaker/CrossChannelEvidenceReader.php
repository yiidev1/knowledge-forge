<?php

declare(strict_types=1);

namespace App\AudioToText\Application\Speaker;

use App\AudioToText\Domain\AudioConversationRepositoryInterface;
use App\AudioToText\Domain\JobStatus;
use App\AudioToText\Domain\RecordingType;
use App\AudioToText\Domain\Speaker\CrossChannelEvidence;
use App\AudioToText\Domain\SpeakerRole;
use App\AudioToText\Domain\TranscriptionJob;
use App\AudioToText\Domain\TranscriptionJobRepositoryInterface;

/**
 * Whether this recording has a proven opposite-channel sibling, and what that sibling's timings say.
 *
 * ## One-sided on purpose
 *
 * This runs while a deterministic child is being completed, and reads the sibling's **already stored**
 * turns. The second channel of a call to finish therefore gets cross-channel help and the first does
 * not — the worker claims one job per run, `ORDER BY id ASC`, so the first child was completed before
 * the second one's audio had even been read.
 *
 * The alternative was to go back and re-segment the first child once the second landed. That is the one
 * thing this must not do: `speaker_segments` on a completed job is what `ReviewConversationService`
 * reads as the machine layer, what a reviewer's first correction is copied from, and what `revert()`
 * falls back to. Rewriting it would make an already-reviewed child's Revert land on turns the reviewer
 * never saw. Writing the second child's segments once, before any review of it can exist, costs nothing
 * and is the entire reason this design was chosen over the three alternatives considered.
 *
 * So a defect on the first-completed channel stays. That is accepted: a partial repair that cannot
 * corrupt a reviewed transcript is worth more than a complete one that can.
 *
 * ## Every guard is a veto
 *
 * A wrong sibling is far worse than no sibling — it would confirm handovers from an unrelated
 * conversation and cut this one at random. So this returns null unless all six conditions below are
 * *proven*, and every ambiguity resolves to null, which leaves the caller with the unchanged
 * single-channel result.
 */
final readonly class CrossChannelEvidenceReader
{
    public function __construct(
        private AudioConversationRepositoryInterface $conversations,
        private TranscriptionJobRepositoryInterface $jobs,
        private SpeakerSegmentsDecoder $decoder,
    ) {}

    public function for(TranscriptionJob $job): ?CrossChannelEvidence
    {
        // 1. A declared single-speaker channel. A mixed recording is diarized, and this says nothing
        //    about what a diarizer should conclude.
        $role = $job->sourceRole?->speakerRole();
        $conversationId = $job->conversationId;

        // A job with no conversation row predates grouping entirely, so it has no channel and no sibling.
        if ($role === null || $conversationId === null) {
            return null;
        }

        // 2. The supported deterministic flow, which is the Order58 CALLER/CALLEE import. A generic
        //    separate upload is two files an administrator happened to send together: no shared clock,
        //    no common start, and `Job/Conversion/Action` already refuses to invent one. An untyped or
        //    mixed row is not one side of a call.
        $type = $this->conversations->recordingTypeFor($conversationId);
        $channelRole = $this->roleFor($type);

        if ($channelRole === null) {
            return null;
        }

        // The channel the file arrived on and the role the job was queued with must agree. If they do
        // not, something re-labelled one of them and neither can be trusted to name a side of this call.
        if ($channelRole !== $role) {
            return null;
        }

        // 4. The same real source call. `call_session_id` is the provider's own identity for the call,
        //    carried across the Order58 seam by `RecordingImportProcessor` precisely so the audio side
        //    can tell which recordings belong together, and scoped by store because two stores can hand
        //    out the same number. Nothing is inferred from a filename and no identifier is invented: a
        //    row without one simply does not qualify.
        $callSessionId = $this->conversations->callSessionFor($conversationId);
        $storeSourceId = $this->conversations->storeSourceIdFor($conversationId);

        if ($callSessionId === null || $storeSourceId === null) {
            return null;
        }

        $opposite = $type === RecordingType::Caller ? RecordingType::Callee : RecordingType::Caller;
        $oppositeRole = $this->roleFor($opposite);

        // 3. Exactly one completed opposite-role sibling. Two of them is the ambiguous case — a channel
        //    re-imported or re-uploaded leaves more than one completed row for the same side, and there
        //    is no basis here for choosing between them.
        $sibling = null;

        foreach ($this->conversations->channelJobIdsForCallSession($storeSourceId, $callSessionId)[$opposite->value] ?? [] as $candidateId) {
            if ($candidateId === $job->id) {
                continue;
            }

            $candidate = $this->jobs->findById($candidateId);

            if ($candidate === null
                || $candidate->status !== JobStatus::COMPLETED
                || $candidate->sourceRole?->speakerRole() !== $oppositeRole
                // 6. Stored segments. A completed channel whose timings were unusable has null here,
                //    and one bubble spanning the call cannot confirm anything.
                || $candidate->speakerSegmentsJson === null) {
                continue;
            }

            if ($sibling !== null) {
                return null;
            }

            $sibling = $candidate;
        }

        if ($sibling === null) {
            return null;
        }

        // 5. And the sibling's timeline has to work as a clock. {@see CrossChannelEvidence} decides.
        return CrossChannelEvidence::fromSiblingTurns($this->decoder->decode($sibling->speakerSegmentsJson));
    }

    /**
     * CALLER is the Customer and CALLEE is the Agent — an asserted fact about the recordings, not
     * something inferred from the audio. The same mapping {@see \App\AudioToText\Application\SharedConversationReader}
     * applies; it is stated twice rather than shared because each is free to stop trusting it alone.
     */
    private function roleFor(?RecordingType $type): ?SpeakerRole
    {
        return match ($type) {
            RecordingType::Caller => SpeakerRole::CUSTOMER,
            RecordingType::Callee => SpeakerRole::AGENT,
            RecordingType::Mixed, null => null,
        };
    }
}
