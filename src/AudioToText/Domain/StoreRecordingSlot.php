<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

use App\AudioToText\Domain\Tts\AiAudioState;
use App\AudioToText\Domain\Tts\TtsRendition;
use App\AudioToText\Domain\Tts\TtsStatus;
use DateTimeImmutable;

use function count;

/**
 * One recording as a store's listing needs it: enough to decide what to draw, and nothing more.
 *
 * ## Summary only, on purpose
 *
 * A page of twenty orders can stand behind sixty recordings. Carrying each one's transcript would mean
 * megabytes of JSON rendered into a table nobody has clicked yet, so this object holds **flags rather
 * than content** — `hasTranscript` instead of the transcript, `hasOriginalAudio` instead of a path. The
 * text arrives when a modal asks for it, which is the whole reason the modals fetch.
 *
 * ## The AI-audio state here is the cheap one
 *
 * `aiAudioState()` is read from the rendition row alone: is there a file, is something running, did it
 * fail. It deliberately cannot report `Stale` or `Blocked`, because both of those require digesting the
 * current transcript — per recording, per row — which is exactly the work a listing must not do.
 *
 * The authoritative answer, including staleness and the speaker-confirmation gate, comes from
 * `AiAudioPage` when the Generate modal opens. So this state answers "can I press play, is something
 * happening" and that page answers "may this be generated", which are different questions.
 */
final readonly class StoreRecordingSlot
{
    /**
     * @param list<self> $older previous recordings of the same type, newest first; empty for most
     */
    public function __construct(
        public string $conversationPublicId,
        public string $jobPublicId,
        /** Null only for a legacy Customer + Agent pair, which predates recording types entirely. */
        public ?RecordingType $recordingType,
        /** COMMON for every modern upload; CUSTOMER or AGENT inside a legacy separate pair. */
        public SourceRole $sourceRole,
        public JobStatus $status,
        public TranscriptionProvider $provider,
        public ?float $durationSeconds,
        public DateTimeImmutable $uploadedAt,
        public string $originalFilename,
        /** Whether the uploaded recording is still on disk and can therefore be played. */
        public bool $hasOriginalAudio,
        /** Whether the machine produced any text at all — the gate for offering a transcript. */
        public bool $hasTranscript,
        /** Whether speakers were separated, which is what makes a turn-by-turn view possible. */
        public bool $hasSegments,
        public bool $rolesConfirmed,
        public ?TtsRendition $rendition = null,
        public array $older = [],
    ) {}

    /**
     * What this recording is called on screen.
     *
     * A legacy pair has no recording type, so it falls back to the role the administrator supplied when
     * they uploaded it — "Customer" or "Agent", the words this application has always used for them.
     * It is **not** relabelled Caller or Callee: nothing here knows the direction of the call, and
     * saying otherwise would be inventing a fact to fill a column.
     */
    public function label(): string
    {
        return $this->recordingType?->label() ?? $this->sourceRole->label();
    }

    /** Whether a correction screen has anything to show for this recording. */
    public function isReviewable(): bool
    {
        return $this->status === JobStatus::COMPLETED && $this->hasSegments;
    }

    /** Whether an original-transcript view has anything to show — segments or plain text. */
    public function hasReadableTranscript(): bool
    {
        return $this->status === JobStatus::COMPLETED && ($this->hasSegments || $this->hasTranscript);
    }

    public function hasGeneratedAudio(): bool
    {
        return $this->rendition?->isPlayable() === true;
    }

    /**
     * The state the Text-to-Audio column shows. See the class docblock for what it cannot say.
     */
    public function aiAudioState(): AiAudioState
    {
        if ($this->rendition === null) {
            return AiAudioState::NotGenerated;
        }

        return match ($this->rendition->status) {
            TtsStatus::Queued => AiAudioState::Queued,
            TtsStatus::Generating => AiAudioState::Generating,
            // A failed regeneration leaves the previous recording playable, and being able to play it
            // is the more useful thing to say about the cell; the modal reports the failure.
            TtsStatus::Failed => $this->hasGeneratedAudio() ? AiAudioState::Ready : AiAudioState::Failed,
            TtsStatus::Ready => $this->hasGeneratedAudio() ? AiAudioState::Ready : AiAudioState::NotGenerated,
        };
    }

    public function olderCount(): int
    {
        return count($this->older);
    }

    /**
     * This recording plus every older one of its type, newest first.
     *
     * @return non-empty-list<self>
     */
    public function withHistory(): array
    {
        return [$this, ...$this->older];
    }
}
