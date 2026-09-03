<?php

declare(strict_types=1);

namespace App\AudioToText\Application;

use App\AudioToText\Application\Speaker\SpeakerSegmentsDecoder;
use App\AudioToText\Domain\EffectiveConversation;
use App\AudioToText\Domain\TranscriptionJob;

/**
 * The machine's own conversation, whatever has been corrected since.
 *
 * The counterpart to {@see EffectiveConversationReader}, and deliberately not a mode on it: that class
 * exists to *decide* which layer a screen sees, and this one has no decision to make. It always returns
 * the columns the worker wrote — `speaker_segments`, `agent_text`, `customer_text` — which no correction
 * ever rewrites (every review write touches only the `reviewed_*` columns). A separate class with no
 * branch cannot drift from the one that has one, and cannot be reached by accident from the effective
 * path.
 *
 * Both flags are hard-coded false, and the second is the subtle one:
 *
 *  - `isReviewed` is false because this *is* the unreviewed layer.
 *  - `rolesConfirmed` is false because an administrator's confirmation is a statement about the
 *    **reviewed** conversation. Passing the job's real confirmation through would make a machine result
 *    the pipeline refused to publish print "Agent" and "Customer" as fact on the one page whose whole
 *    purpose is to show what the machine actually concluded. A NEEDS_REVIEW recording stays
 *    Speaker 1 / Speaker 2 here permanently, however it was later confirmed.
 */
final readonly class MachineConversationReader
{
    public function __construct(private SpeakerSegmentsDecoder $decoder) {}

    public function for(TranscriptionJob $job): EffectiveConversation
    {
        return new EffectiveConversation(
            $this->decoder->decode($job->speakerSegmentsJson),
            $job->agentText,
            $job->customerText,
            false,
            false,
        );
    }
}
