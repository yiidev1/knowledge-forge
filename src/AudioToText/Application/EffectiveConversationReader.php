<?php

declare(strict_types=1);

namespace App\AudioToText\Application;

use App\AudioToText\Application\Speaker\SpeakerSegmentsDecoder;
use App\AudioToText\Domain\EffectiveConversation;
use App\AudioToText\Domain\TranscriptionJob;

/**
 * The one place that decides which conversation a job actually has.
 *
 * Every surface that shows turns or agent/customer text goes through here — the detail page now, the
 * download and the conversions list shortly, and any later consumer. That is the whole point: when an
 * administrator can correct speaker attribution, "which version is authoritative" must be answered once
 * rather than repeated at each call site, where the copies inevitably drift apart.
 *
 * **Today it always returns the raw machine result**, because nothing writes a reviewed conversation
 * yet. That is deliberate: this step introduces the seam and changes no behaviour, so the existing
 * suite passing is proof that the plumbing is correct before any data starts flowing through it.
 *
 * When the reviewed layer lands, only this method changes — prefer `reviewed_segments` and the text
 * derived from them when present, fall back to the machine's output otherwise. No caller is touched.
 */
final readonly class EffectiveConversationReader
{
    public function __construct(private SpeakerSegmentsDecoder $decoder) {}

    public function for(TranscriptionJob $job): EffectiveConversation
    {
        if ($job->isReviewed()) {
            return new EffectiveConversation(
                $this->decoder->decode($job->reviewedSegmentsJson),
                // NULL until an administrator confirms the roles, which is what keeps a corrected but
                // unconfirmed conversation rendering as Speaker 1 / Speaker 2 through the existing gate.
                $job->reviewedAgentText,
                $job->reviewedCustomerText,
                true,
                $job->rolesConfirmed(),
            );
        }

        return new EffectiveConversation(
            $this->decoder->decode($job->speakerSegmentsJson),
            $job->agentText,
            $job->customerText,
            false,
            $job->rolesConfirmed(),
        );
    }
}
