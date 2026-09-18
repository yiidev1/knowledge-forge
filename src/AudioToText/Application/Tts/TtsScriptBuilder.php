<?php

declare(strict_types=1);

namespace App\AudioToText\Application\Tts;

use App\AudioToText\Application\EffectiveConversationReader;
use App\AudioToText\Domain\Speaker\SpeakerUtterance;
use App\AudioToText\Domain\SpeakerRole;
use App\AudioToText\Domain\TranscriptionJob;
use App\AudioToText\Domain\Tts\TtsOutputType;
use App\AudioToText\Domain\Tts\TtsScript;
use App\AudioToText\Domain\Tts\TtsUtterance;

/**
 * Decides what the AI audio will say — and, just as importantly, reads it from the one place that knows.
 *
 * ## The corrected transcript wins, always
 *
 * Everything here goes through {@see EffectiveConversationReader}, which is this application's existing
 * authority on which version of a conversation is current: the reviewed layer when an administrator has
 * corrected it, the machine's own output otherwise. That single hop is what makes the feature worth
 * having. When speech recognition heard "one ton" and somebody corrected it to "wonton", the generated
 * audio says **wonton** — because the reader hands back the correction and nothing downstream is allowed
 * to reach past it for the raw column.
 *
 * ## Two shapes of source, one shape of output
 *
 * A mixed recording has diarized turns, so a mixed rendition is those turns in their original order. A
 * separate Customer or Agent recording was never diarized at all — the role was declared at upload, so
 * nothing was inferred — and its whole transcript is one side of the call. Both arrive here as a list of
 * utterances, so everything after this point handles one shape.
 *
 * ## Nothing is rewritten
 *
 * The only transformation applied is {@see TtsSourceText::prepare()}, whose complete contents are
 * documented on that class: `>>` markers removed, invalid UTF-8 repaired, outer whitespace trimmed. No
 * summarising, no paraphrasing, no language model, and no display formatting — in particular
 * {@see \App\AudioToText\Application\SpokenPrice} is not applied, because a price is exactly the kind of
 * thing this must not decide it knows better about.
 */
final readonly class TtsScriptBuilder
{
    public function __construct(private EffectiveConversationReader $effective) {}

    /**
     * Build the script for one output, or an empty one when there is nothing to say.
     *
     * Returns rather than throws on empty: "this side of the call said nothing" is a fact about the
     * recording, and the caller is better placed to decide whether that is worth an error — it is when
     * somebody pressed a button, and it is not when the queue is deciding what to offer.
     */
    public function build(TranscriptionJob $job, TtsOutputType $outputType): TtsScript
    {
        return $outputType === TtsOutputType::Mixed
            ? $this->mixed($job)
            : $this->singleRole($job, $outputType === TtsOutputType::Agent ? SpeakerRole::AGENT : SpeakerRole::CUSTOMER);
    }

    /**
     * Both speakers, in the order they spoke.
     *
     * Only AGENT and CUSTOMER turns are voiced. A turn mapped to OTHER or UNKNOWN has no voice that could
     * speak it without claiming something — see {@see TtsScript} — so it is counted and reported instead
     * of being guessed at or dropped in silence.
     */
    private function mixed(TranscriptionJob $job): TtsScript
    {
        $utterances = [];
        $omitted = 0;

        foreach ($this->effective->for($job)->utterances as $utterance) {
            /** @var SpeakerUtterance $utterance */
            if ($utterance->role !== SpeakerRole::AGENT && $utterance->role !== SpeakerRole::CUSTOMER) {
                $omitted++;

                continue;
            }

            $text = TtsSourceText::prepare($utterance->text);

            if ($text === '') {
                // A turn that was only a speaker marker, or only whitespace. Not omitted content — there
                // was nothing in it to say — so it does not count against the reported total.
                continue;
            }

            $utterances[] = new TtsUtterance($utterance->role, $text);
        }

        return new TtsScript($utterances, $omitted);
    }

    /**
     * One side of the call, in one voice.
     *
     * Three sources, tried in the order of how specific they are. A mixed recording has turns, so the
     * role's own turns are the best answer and preserve the natural pauses between them. A separate
     * upload has no turns at all, and its side is held whole in `agent_text` / `customer_text` — which
     * the effective reader resolves to the reviewed column when one exists. `transcript` is the last
     * resort for a separate child whose role columns were somehow never written; it holds the same words.
     */
    private function singleRole(TranscriptionJob $job, SpeakerRole $role): TtsScript
    {
        $effective = $this->effective->for($job);
        $utterances = [];

        foreach ($effective->utterances as $utterance) {
            /** @var SpeakerUtterance $utterance */
            if ($utterance->role !== $role) {
                continue;
            }

            $text = TtsSourceText::prepare($utterance->text);

            if ($text !== '') {
                $utterances[] = new TtsUtterance($role, $text);
            }
        }

        if ($utterances !== []) {
            return new TtsScript($utterances);
        }

        $whole = $role === SpeakerRole::AGENT ? $effective->agentText : $effective->customerText;
        $text = TtsSourceText::prepare($whole ?? $job->transcript ?? '');

        return new TtsScript($text === '' ? [] : [new TtsUtterance($role, $text)]);
    }
}
