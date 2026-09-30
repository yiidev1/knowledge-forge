<?php

declare(strict_types=1);

namespace App\AudioToText\Application;

use App\AudioToText\Domain\AudioConversationRepositoryInterface;
use App\AudioToText\Domain\RecordingType;
use App\AudioToText\Domain\Speaker\DerivedConversation;
use App\AudioToText\Domain\SpeakerRole;
use App\AudioToText\Domain\TranscriptionJob;
use App\AudioToText\Domain\TranscriptionJobRepositoryInterface;

/**
 * The conversation a channel recording belongs to, when there is one and it speaks for the call.
 *
 * ## The problem it answers
 *
 * A call arrives as up to three recordings, and until now nothing said they were one call. Corrections
 * made on the mixed conversation — which is the only one where the two speakers are told apart — were
 * invisible on the Caller and Callee pages, because those are different jobs with their own transcripts
 * and their own reviewed layers. An administrator who fixed a turn saw it fixed in one place and not in
 * the other two, which reads as data loss whichever page you trust.
 *
 * ## Why it derives rather than copies
 *
 * Copying the corrected text into the channel jobs would need a mapping between their turns, and there
 * is none: the three transcripts disagree about words, boundaries and timings because they are three
 * transcriptions of three different files. Copying would also create a second writer for the same fact
 * and destroy corrections the channel recordings already carry. So the mixed conversation stays the one
 * copy, and the channel pages borrow it — see {@see DerivedConversation}.
 *
 * ## The four conditions, all of them required
 *
 * 1. **The recording is a Caller or a Callee.** A mixed recording is the source and never derives from
 *    anything; a legacy SEPARATE half carries its identity in `source_role` and is left alone.
 * 2. **It records which call it is of.** Null for every upload made by hand, which is what keeps this
 *    entirely dormant until the importer runs or somebody links the rows deliberately.
 * 3. **That call has exactly one mixed recording with confirmed roles.** None and several both answer
 *    null — several is a normal state, because the same recording uploaded twice produces it, and
 *    choosing between them would be inventing an answer. Asked in the repository so "exactly one"
 *    cannot be decided differently by two callers.
 * 4. **The roles were confirmed by a person.** An unconfirmed mixed conversation has not established
 *    which side is the agent, so it has nothing to lend.
 *
 * Any one of them failing returns null, and the page renders exactly what it renders today.
 *
 * ## The assumption, stated plainly
 *
 * Caller is read as the Customer and Callee as the Agent. That mapping is not introduced here — it is
 * already the product's display contract, in {@see \App\Shared\Audio\RecordingTypeLabels}, and it is
 * what a Callee page means when it tells the reader it is the Agent side of the call. This inherits it
 * rather than adding a second opinion. It is nonetheless an assumption about who dialled whom, and it is
 * the thing to revisit first if a restaurant ever returns a customer's call.
 */
final readonly class SharedConversationReader
{
    public function __construct(
        private AudioConversationRepositoryInterface $conversations,
        private TranscriptionJobRepositoryInterface $jobs,
        private EffectiveConversationReader $effective,
    ) {}

    public function for(TranscriptionJob $job): ?DerivedConversation
    {
        if ($job->conversationId === null) {
            return null;
        }

        // A legacy Customer + Agent pair already knows whose words it holds and is never diarized. It
        // has nothing to gain here and its presentation is not this feature's to change.
        if ($job->sourceRole?->isProvided() === true) {
            return null;
        }

        $role = $this->roleFor($this->conversations->recordingTypeFor($job->conversationId));

        if ($role === null) {
            return null;
        }

        $callSessionId = $this->conversations->callSessionFor($job->conversationId);

        if ($callSessionId === null) {
            return null;
        }

        $storeSourceId = $this->conversations->storeSourceIdFor($job->conversationId);

        if ($storeSourceId === null) {
            return null;
        }

        $mixedJobId = $this->conversations->confirmedMixedJobIdForCallSession($storeSourceId, $callSessionId);

        if ($mixedJobId === null) {
            return null;
        }

        $mixed = $this->jobs->findById($mixedJobId);

        if ($mixed === null) {
            return null;
        }

        // Through the existing reader, so a mixed conversation that was confirmed but never edited
        // derives from its machine segments and an edited one from its reviewed layer, with no second
        // rule anywhere about which layer wins.
        $conversation = $this->effective->for($mixed);

        $utterances = [];

        foreach ($conversation->utterances as $utterance) {
            if ($utterance->role === $role) {
                $utterances[] = $utterance;
            }
        }

        // A confirmed conversation with nothing on this side is not something to show as this
        // recording's words. The page falls back to the recording's own transcript, which at least
        // describes the file the reader is listening to.
        if ($utterances === []) {
            return null;
        }

        // The job's public id, because that is what the review route is keyed on — a reader following
        // the link lands on the page where these corrections are actually made.
        return new DerivedConversation($utterances, $role, $mixed->publicId);
    }

    /**
     * Which role a channel's words belong to, or null for a recording that is not one side of a call.
     *
     * Mixed returns null because it is the source. A conversation with no declared type predates the
     * cards and is treated as a conversation, exactly as {@see RecordingVoiceReader} treats it.
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
