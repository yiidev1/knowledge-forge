<?php

declare(strict_types=1);

namespace App\AudioToText\Application\Tts;

use App\AudioToText\Application\AudioToTextSettings;
use App\AudioToText\Domain\JobStatus;
use App\AudioToText\Domain\TranscriptionJob;
use App\AudioToText\Domain\Tts\TtsEnqueueOutcome;
use App\AudioToText\Domain\Tts\TtsException;
use App\AudioToText\Domain\Tts\TtsOutputType;
use App\AudioToText\Domain\Tts\TtsRendition;
use App\AudioToText\Domain\Tts\TtsRenditionRepositoryInterface;

use function in_array;

/**
 * The one place a paid generation is authorised. Every trigger goes through here.
 *
 * ## Five ways to ask, one way to be answered
 *
 * 1. The transcription worker, when a job finishes and its upload asked for AI audio.
 * 2. The same request honoured later, when an administrator confirms the speakers on a mixed recording
 *    whose roles were not publishable at the time.
 * 3. A Generate click.
 * 4. A Regenerate click.
 * 5. A retry after a failure.
 *
 * All five call {@see enqueue()}. That is what stops a double click, a refresh, an automatic trigger
 * landing beside a manual one, and a confirmation arriving while a worker is already mid-flight from
 * turning into four charges for one recording. The repository makes the decision atomic; this class
 * decides what may be asked for in the first place.
 *
 * ## The roles gate, and the trap in it
 *
 * `TranscriptionJob::rolesConfirmed()` is **false for every child of a separate upload** — and always
 * will be. `markCompletedWithProvidedRole()` writes `speaker_separation_status => null` and never sets
 * `roles_confirmed_at`, because nothing was inferred so nothing is claimed. Writing the gate as
 * `$job->rolesConfirmed()`, which reads exactly like "the roles are known", would therefore silently
 * disable AI audio for every Customer + Agent upload ever made.
 *
 * {@see rolesAreKnown()} is the correct predicate and exists so that reading is impossible to reach for.
 */
final readonly class TtsGenerationService
{
    private const PROVIDER = 'DEEPGRAM';

    public function __construct(
        private TtsRenditionRepositoryInterface $renditions,
        private TtsScriptBuilder $scripts,
        private AudioToTextSettings $settings,
    ) {}

    /**
     * Whether a voice may be put to this recording's speakers at all.
     *
     * Two routes, and they are genuinely different kinds of evidence:
     *
     *  - **The administrator said so at upload.** A separate Customer or Agent file was labelled by a
     *    person before a byte was processed. Nothing was inferred, so there is nothing to confirm — and
     *    this is the stronger of the two.
     *  - **The application published them.** A mixed recording's roles came out of diarization and role
     *    mapping, and are shown as fact only once `speaker_separation_status` is COMPLETED or somebody
     *    confirmed them by hand.
     *
     * Until one holds, `ConversationView` renders "Speaker 1" and "Speaker 2" rather than names. Choosing
     * a Customer voice is the same assertion as printing the word, and a louder one, since audio carries
     * no way to mark itself provisional.
     */
    public function rolesAreKnown(TranscriptionJob $job): bool
    {
        // A recording that declared its side answers the question this gate asks, and answers it more
        // firmly than a confirmation could: a person said so at upload. Without this such a recording
        // is blocked for ever — its confirmation needs a turn in both AGENT and CUSTOMER, which one
        // person's audio may never have, so there is no action that could unblock it.
        if ($this->scripts->voiceFor($job) !== null) {
            return true;
        }

        return $job->sourceRole?->isProvided() === true || $job->rolesConfirmed();
    }

    /**
     * Whether this recording is far enough along to generate from.
     *
     * Completion is required for the obvious reason — there is no transcript before it — and the roles
     * gate for the one above.
     */
    public function isEligible(TranscriptionJob $job): bool
    {
        return $job->status === JobStatus::COMPLETED && $this->rolesAreKnown($job);
    }

    /**
     * What this recording can produce. One type in this phase; see {@see TtsOutputType::availableFor()}.
     *
     * @return list<TtsOutputType>
     */
    public function availableTypes(TranscriptionJob $job): array
    {
        return TtsOutputType::availableFor($job->sourceRole);
    }

    /**
     * The digest of what would be spoken right now.
     *
     * Recomputed on every render rather than cached, because it is the whole staleness mechanism: it has
     * to reflect a correction the moment it is saved, and a cache that lagged by even one request would
     * show an administrator the reassuring answer immediately after they changed something.
     *
     * @throws TtsException when the transcript cannot be reduced to a digest at all
     */
    public function currentHash(TranscriptionJob $job, TtsOutputType $outputType): string
    {
        return TtsSourceDigest::for($outputType, $this->scripts->build($job, $outputType)->utterances);
    }

    /**
     * The voice and format settings in force now, for comparison against what a file was made with.
     *
     * The job is optional only so the one caller that has a rendition and not its recording can still
     * ask. Everywhere a job is available it is passed, because a single-side recording's key depends
     * on its own voice and on neither of the two a conversation uses.
     */
    public function currentRenderKey(TtsOutputType $outputType, ?TranscriptionJob $job = null): string
    {
        return TtsRenderKey::for(
            $this->settings->tts,
            $outputType,
            $job === null ? null : $this->scripts->voiceFor($job),
        );
    }

    public function find(TranscriptionJob $job, TtsOutputType $outputType): ?TtsRendition
    {
        return $this->renditions->findForJob($job->id, $outputType);
    }

    /**
     * Ask for a generation.
     *
     * Refuses, without spending anything, when:
     *
     *  - the output is not one this recording can produce;
     *  - the recording is not complete, or its speakers are not known;
     *  - there is nothing to say;
     *  - audio for this exact transcript **and** these exact voices already exists;
     *  - an attempt is already queued or running.
     *
     * `$expectedHash` is the optimistic lock for the page: the form carries the digest it was rendered
     * from, and a mismatch means the transcript moved under a stale tab. Refusing then is the difference
     * between buying what the administrator saw and buying something they have not read. It is the same
     * idiom `review_count` already applies to speaker corrections. Null skips the check, which is what
     * the automatic triggers pass — they are acting on whatever is current by definition.
     */
    public function enqueue(
        TranscriptionJob $job,
        TtsOutputType $outputType,
        ?int $adminId,
        ?string $expectedHash = null,
    ): TtsEnqueueOutcome {
        if (!in_array($outputType, $this->availableTypes($job), true)) {
            throw TtsException::outputNotAvailable($outputType);
        }

        if ($job->status !== JobStatus::COMPLETED) {
            throw TtsException::nothingToSpeak($outputType);
        }

        if (!$this->rolesAreKnown($job)) {
            throw TtsException::rolesNotKnown();
        }

        $script = $this->scripts->build($job, $outputType);

        if ($script->isEmpty()) {
            throw TtsException::nothingToSpeak($outputType);
        }

        $hash = TtsSourceDigest::for($outputType, $script->utterances);

        if ($expectedHash !== null && $expectedHash !== $hash) {
            // Not an error to be alarmed by — somebody corrected the transcript in another tab — but the
            // button that was pressed was labelled with the old text, so it is not the one to honour.
            return TtsEnqueueOutcome::AlreadyCurrent;
        }

        $existing = $this->renditions->findForJob($job->id, $outputType);

        if ($existing !== null && $existing->isCurrent($hash, $this->currentRenderKey($outputType, $job))) {
            return TtsEnqueueOutcome::AlreadyCurrent;
        }

        // The repository re-checks the in-flight condition inside the write, so the read above is an
        // early exit rather than the guarantee. Two requests arriving together both get past it and
        // exactly one of them queues.
        return $this->renditions->enqueue($job->id, $outputType, $hash, $adminId, self::PROVIDER);
    }

    /**
     * Honour an upload's own request, if there was one and it can be honoured yet.
     *
     * Called from the transcription worker and from speaker confirmation. Deliberately swallows every
     * reason not to act and returns a plain boolean: neither caller may be disturbed by this. A failure
     * to queue optional audio must never fail a transcription that has just succeeded, nor a speaker
     * confirmation an administrator is waiting on.
     *
     * `$wasRequested` comes from `audio_conversations.generate_ai_audio`. When it is false this does
     * nothing at all, which is what makes the checkbox's default of off mean off.
     */
    public function enqueueRequested(TranscriptionJob $job, bool $wasRequested): bool
    {
        if (!$wasRequested || !$this->isEligible($job)) {
            return false;
        }

        $queued = false;

        foreach ($this->availableTypes($job) as $outputType) {
            try {
                $queued = $this->enqueue($job, $outputType, null)->queued() || $queued;
            } catch (TtsException) {
                // Nothing to say, or an output this recording cannot produce. Both are ordinary facts
                // about the recording rather than faults, and the page states them; there is nothing for
                // a worker to do about either.
                continue;
            }
        }

        return $queued;
    }
}
