<?php

declare(strict_types=1);

namespace App\AudioToText\Application;

use App\AudioToText\Domain\EffectiveConversation;
use App\AudioToText\Domain\Speaker\ConversationView;
use App\AudioToText\Domain\Speaker\DerivedConversation;
use App\AudioToText\Domain\Speaker\TranscriptVoice;
use App\AudioToText\Domain\TranscriptionJob;

/**
 * Which conversation a screen shows: this recording's own, or the call's.
 *
 * The decision is one line long and there are six screens that have to make it, which is exactly the
 * shape of thing that ends up made differently in two of them. It lives here for the same reason
 * {@see EffectiveConversationReader} decides machine-or-reviewed in one place: a reader who sees
 * corrected text on the detail page and uncorrected text in the dialog has been shown a bug, whichever
 * of the two is right.
 *
 * The lookup is skipped entirely for a mixed recording — `$voice` is non-null exactly for the Caller and
 * Callee uploads that can borrow, so the common path costs no extra query.
 */
final readonly class ConversationPresenter
{
    public function __construct(private SharedConversationReader $shared) {}

    /** The borrowed conversation for this recording, or null when it shows its own. */
    public function derivedFor(TranscriptionJob $job, ?TranscriptVoice $voice): ?DerivedConversation
    {
        return $voice === null ? null : $this->shared->for($job);
    }

    public function for(
        TranscriptionJob $job,
        EffectiveConversation $effective,
        ?TranscriptVoice $voice,
        ?DerivedConversation $derived = null,
    ): ConversationView {
        $derived ??= $this->derivedFor($job, $voice);

        if ($derived !== null && $voice !== null) {
            return ConversationView::derived($derived->utterances, $derived->role, $voice);
        }

        return ConversationView::from(
            $job->speakerSeparationStatus,
            $effective->utterances,
            $job->speakerRoleConfidence,
            $effective->hasSeparatedText(),
            $effective->rolesConfirmed,
            $voice,
        );
    }
}
