<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Conversion\AiAudio;

use App\AudioToText\Domain\SourceRole;
use App\AudioToText\Domain\TranscriptionJob;
use App\AudioToText\Domain\Tts\AiAudioState;
use App\AudioToText\Domain\Tts\TtsOutputType;
use App\AudioToText\Domain\Tts\TtsRendition;

/**
 * One generated output, ready to render. Every decision already made.
 *
 * The template chooses no state, computes no digest and compares no hash — it prints what is here. That
 * is the same rule `ConversationView` follows for turns, and for the same reason: a template that worked
 * out whether audio was stale would be a second implementation of staleness, and the two would drift.
 */
final readonly class AiAudioRow
{
    public function __construct(
        /** The recording this output is made from, and whose public id addresses its file. */
        public TranscriptionJob $job,
        public TtsOutputType $outputType,
        public ?TtsRendition $rendition,
        public AiAudioState $state,
        /** The digest of what would be spoken now. Posted with the form so a stale tab cannot buy. */
        public string $currentHash,
        /** Characters that would be billed. Shown before the button, because it is a fair thing to know. */
        public int $characterCount,
        public int $turnCount,
        /**
         * Turns with no voice that could honestly speak them — a third party, or unattributed speech.
         *
         * Reported rather than hidden: this feature promises a clean reading of the transcript, so
         * leaving part of it out silently would be the one dishonesty it cannot afford.
         */
        public int $omittedTurns,
        public bool $canGenerate,
        /** Why not, in the administrator's terms, when {@see $canGenerate} is false. */
        public ?string $blockedReason = null,
    ) {}

    public function isPlayable(): bool
    {
        return $this->rendition?->isPlayable() === true;
    }

    /** The heading for this block: "Mixed AI audio", "Customer AI audio", "Agent AI audio". */
    public function title(): string
    {
        return $this->outputType->label();
    }

    /**
     * Which recording this came from, for a separate upload where two blocks sit on one page.
     *
     * Null for a mixed upload, where saying "from the recording" would only restate the page.
     */
    public function sourceLabel(): ?string
    {
        return $this->job->sourceRole?->isProvided() === true
            ? $this->job->sourceRole->label() . ' recording'
            : null;
    }

    public function isCustomerSide(): bool
    {
        return $this->job->sourceRole === SourceRole::Customer;
    }

    /**
     * What the button should say.
     *
     * "Regenerate" only where there is something to replace — offering it against nothing generated yet
     * would suggest a previous version exists.
     */
    public function buttonLabel(): string
    {
        return $this->isPlayable() ? 'Regenerate' : 'Generate';
    }

    /**
     * Whether the control is drawn at all — which is NOT whether it may be pressed.
     *
     * {@see $canGenerate} answers "would pressing this do anything now?", and it is false while a worker
     * is already generating. Rendering on that alone made the button vanish the moment it was pressed:
     * the answer arrived, the panel re-read, the state was Queued, and the control the operator had just
     * used was gone. Nothing was wrong, but the only evidence of that was its absence.
     *
     * So the two questions are separated. This one stays true through the whole lifecycle — queued,
     * generating, finished, failed — and the control reports the state instead of disappearing into it.
     * The states where nothing is offered are the ones where nothing ever will be until something else
     * changes: no provider, nothing said on this side of the call, speakers not published.
     */
    public function isActionOffered(): bool
    {
        return $this->canGenerate || $this->state->isInFlight();
    }

    /**
     * What the control says right now, including while it is disabled.
     *
     * The whole string, not a fragment the browser completes: "Queued…" and "Regenerate AI Audio" are
     * not the same sentence with a word swapped, and a client assembling them would be the second place
     * that decides what a state is called.
     */
    public function actionLabel(): string
    {
        return match ($this->state) {
            // "Starting…", not "Queued…". This is the label on the control somebody has just pressed,
            // which makes it the primary status they read — and the name of the mechanism is not what
            // they need at that moment. The state's own badge still says Queued, on the listing where
            // it is a column of facts rather than an answer to a press.
            AiAudioState::Queued => 'Starting…',
            AiAudioState::Generating => 'Generating…',
            default => $this->buttonLabel() . ' AI Audio',
        };
    }

    /**
     * Whether pressing the button will cost money.
     *
     * NOT simply "the button is offered". It used to be: the button was hidden for audio that was
     * already current, so every possible press was a paid one. The button is now offered in that state
     * too — so that an administrator can ask the question and be told the answer — and this had to stop
     * meaning the same thing, or the confirmation would warn about a charge that cannot occur.
     *
     * Current audio is the one state where pressing it is free: `TtsGenerationService::enqueue()`
     * matches the digest, returns AlreadyCurrent and queues nothing.
     *
     * ## It is also what the AI audio page renders its form on
     *
     * That page has no confirmation step — its button posts immediately — and the note beside it reads
     * "will be sent to the speech provider. This is a paid action." Offering it for current audio would
     * make that sentence false, so the page asks this narrower question and shows "Up to date with the
     * current transcript." instead. This is exactly the condition that branch carried before
     * {@see $canGenerate} was widened, so that page behaves as it always did.
     */
    public function isPaidAction(): bool
    {
        return $this->canGenerate && !$this->isAlreadyCurrent();
    }

    /**
     * Whether the audio on disk already says exactly what the current transcript says.
     *
     * The confirmation dialog's whole job: it is the difference between "this will generate new audio"
     * and "there is nothing to generate". Derived from the state the page already computed, which is
     * where the digest comparison lives — never re-derived here, or the two could disagree.
     */
    public function isAlreadyCurrent(): bool
    {
        return $this->state === AiAudioState::Ready;
    }
}
