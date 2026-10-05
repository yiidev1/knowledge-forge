<?php

declare(strict_types=1);

namespace App\Shared\Audio;

/**
 * How one recording of a call should be processed, decided in exactly one place.
 *
 * ## The business rule this owns
 *
 * For this integration **caller is the customer and callee is the agent**. That is a decision about the
 * product, not something the audio proves, and it had previously been deliberately withheld from the
 * pipeline: a caller recording was transcribed as though its speakers were still unknown. The
 * consequence was visible — a single-speaker file handed to a two-speaker diarizer yields one cluster
 * and therefore one segment spanning the whole call, which is the giant bubble this architecture
 * removes.
 *
 * The mapping now exists, and it exists **here only**. Scattering it across an importer, a controller
 * and a template is how two of them end up disagreeing, and a disagreement about which side is the
 * customer is not a bug anybody notices quickly.
 *
 * ## Why strings
 *
 * `ModuleIsolationTest` forbids any directory outside the transcription module from naming it — the rule
 * is literal enough that this docblock may not spell the namespace out. The policy is needed by that
 * module *and* by the Order58 importer, so it can live in neither and must speak in storage values. The
 * transcription module maps them back to its own enums and rejects anything it does not recognise, so
 * the looseness costs nothing.
 *
 * ## What this does NOT decide: the shape of the upload
 *
 * It names the **role one file holds** and whether to transcribe it. It deliberately does not answer
 * "which conversation mode", because a single recording is one child whatever side it holds — and the
 * mode that means "roles were supplied" also means "a Customer file and an Agent file arrived
 * together", which is a different upload with two children and its own screens. An earlier draft
 * returned that mode here, and every caller or callee import failed on the second file it then went
 * looking for. The role is the fact; the shape belongs to whoever is doing the uploading.
 *
 * ## A mixed recording is never transcribed
 *
 * Only a file that names a side is transcribed. A caller or callee recording is deterministic whenever
 * somebody names it one — the operator picked that card, or the provider delivered that channel — and
 * either way the side is asserted rather than guessed.
 *
 * Everything else is kept as playable audio and nothing more. That includes a mixed recording whose call
 * has no caller or callee side at all: **a call with no transcript is accepted business behaviour**, and
 * the alternative is the thing this architecture exists to remove — a two-speaker diarizer guessing at
 * an attribution, producing Speaker 1 and Speaker 2 for a conversation whose speakers could simply have
 * been recorded apart.
 *
 * An earlier draft made this conditional on whether the two sides existed. It read well and it was
 * wrong: it meant a store that only ever uploaded mixed files kept the entire old pipeline, so the
 * behaviour anybody actually saw depended on which recordings a call happened to have. Sibling
 * availability does not decide how a file is processed; what the file **is** decides it.
 *
 * ## `$transcribeRequested` can only withdraw
 *
 * It is what the caller asked for — a download made without a transcript in mind passes false — and it
 * is never escalated. A side that was not asked for stays not asked for; a mixed recording is refused
 * whatever was asked.
 */
final readonly class RecordingProcessingPolicy
{
    public const ROLE_COMMON = 'COMMON';
    public const ROLE_CUSTOMER = 'CUSTOMER';
    public const ROLE_AGENT = 'AGENT';

    public const TYPE_MIXED = 'MIXED';
    public const TYPE_CALLER = 'CALLER';
    public const TYPE_CALLEE = 'CALLEE';

    private function __construct(
        /**
         * `COMMON`, `CUSTOMER` or `AGENT` — whose words this file holds.
         *
         * The single fact everything downstream turns on: it is what suppresses diarization, what the
         * screens read to label a channel, and what the combined projection matches a side on.
         */
        public string $sourceRole,
        /** Whether this recording should be queued for transcription at all. */
        public bool $transcribe,
    ) {}

    /**
     * @param string|null $recordingType `MIXED`, `CALLER`, `CALLEE`, or null when nothing was named
     * @param bool        $transcribeRequested what the caller asked for; never escalated, only withdrawn
     */
    public static function decide(?string $recordingType, bool $transcribeRequested = true): self
    {
        return match ($recordingType) {
            self::TYPE_CALLER => new self(self::ROLE_CUSTOMER, $transcribeRequested),
            self::TYPE_CALLEE => new self(self::ROLE_AGENT, $transcribeRequested),
            // MIXED, and anything that named no side at all. The second case is the one worth being
            // explicit about: a request that omits the type must not be a way to get a mixed recording
            // transcribed, and an upload naming nothing is a mixed recording by every other measure.
            default => new self(self::ROLE_COMMON, false),
        };
    }

    /**
     * Whether a recording that already exists may be transcribed on request.
     *
     * The same rule read from the stored row rather than from an upload: a file holds one declared side,
     * or it does not. `source_role` is what carries that for a job — CUSTOMER or AGENT for a channel and
     * for a legacy manual half, COMMON for a mixed recording — so the question needs no second lookup
     * and no second opinion.
     *
     * This is the gate behind the store page's "Transcribe audio" control, and behind the endpoint it
     * posts to. It exists so that offering the button and honouring it are the same decision.
     */
    public static function allowsTranscription(?string $sourceRole): bool
    {
        return $sourceRole === self::ROLE_CUSTOMER || $sourceRole === self::ROLE_AGENT;
    }

    /** Whether the speakers are known from the channel rather than discovered by a diarizer. */
    public function isDeterministic(): bool
    {
        return $this->sourceRole !== self::ROLE_COMMON;
    }
}
