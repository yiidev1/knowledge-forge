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
     * Whether pressing the button will cost money.
     *
     * Always true when it is offered at all: the button is hidden for audio that is already current, so
     * every press that is possible is a paid one. Stated as a method so the template says it once.
     */
    public function isPaidAction(): bool
    {
        return $this->canGenerate;
    }
}
