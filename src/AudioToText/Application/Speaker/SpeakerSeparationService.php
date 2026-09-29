<?php

declare(strict_types=1);

namespace App\AudioToText\Application\Speaker;

use App\AudioToText\Application\AudioToTextSettings;
use App\AudioToText\Domain\Speaker\DegenerateSpeakerClusters;
use App\AudioToText\Domain\Speaker\SpeakerDiarizerInterface;
use App\AudioToText\Domain\Speaker\SeparationBalance;
use App\AudioToText\Domain\Speaker\SeparationReviewReason;
use App\AudioToText\Domain\Speaker\SpeakerSegment;
use App\AudioToText\Domain\Speaker\SpeakerSeparatedTranscript;
use App\AudioToText\Domain\Speaker\SpeakerUtterance;
use App\AudioToText\Domain\Speaker\TranscriptToken;
use App\AudioToText\Domain\SpeakerRole;
use Psr\Log\LoggerInterface;
use Throwable;

use function count;
use function implode;
use function sprintf;
use function trim;

/**
 * Runs the whole speaker-separation stage and returns an outcome — never an exception.
 *
 * That is the contract, and it is the reason this stage is safe to run at all. By the time it is
 * called the transcript is already committed to the database, so nothing it does can put that at risk.
 * A diarizer that is missing, times out, returns nonsense or maps ambiguously all produce a
 * {@see SpeakerSeparatedTranscript} describing what happened; the job stays COMPLETED and the full
 * transcript stays exactly as it was.
 *
 * The other half of the contract is that an uncertain result is reported as uncertain. Filling the
 * agent and customer columns with a coin-flip mapping would be worse than leaving them empty, because
 * nobody re-examines a column that looks finished.
 */
final readonly class SpeakerSeparationService
{
    /**
     * Below this share of attributed speech there is not enough of the conversation on record for a
     * customer/agent split to be worth publishing.
     *
     * Measured **by token duration**, not by counting utterances. Counting treats a one-word "Yes."
     * exactly like a nine-second sentence, so short fragments at turn boundaries can outvote the bulk of
     * the conversation — on the reference call 51% of utterances were unattributed but only 30% of the
     * speech, and after gap-bridging both figures fell to roughly 1%.
     */
    private const MIN_ATTRIBUTED_SHARE = 0.75;

    public function __construct(
        private SpeakerDiarizerInterface $diarizer,
        private SpeakerTranscriptAligner $aligner,
        private SpeakerRoleMapper $roleMapper,
        private AudioToTextSettings $settings,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param list<TranscriptToken> $tokens whisper's timestamped tokens for this recording
     */
    public function separate(string $wavPath, array $tokens): SpeakerSeparatedTranscript
    {
        if (!$this->settings->diarization->enabled) {
            return SpeakerSeparatedTranscript::notSupported('speaker separation is disabled by configuration');
        }

        if (!$this->diarizer->isAvailable()) {
            return SpeakerSeparatedTranscript::notSupported('the local diarization toolchain is not installed');
        }

        if ($tokens === []) {
            return SpeakerSeparatedTranscript::failed(
                $this->diarizer->method(),
                'whisper produced no timestamped tokens to align',
            );
        }

        try {
            $segments = $this->diarizer->diarize($wavPath);
        } catch (Throwable $e) {
            // Technical detail to the log, never to the database or the page.
            $this->logger->warning('Speaker diarization failed; the transcript is unaffected.', [
                'reason' => 'audio_diarization_failed',
                'error_message' => $e->getMessage(),
            ]);

            return SpeakerSeparatedTranscript::failed($this->diarizer->method(), $e->getMessage());
        }

        if ($segments === []) {
            return SpeakerSeparatedTranscript::failed(
                $this->diarizer->method(),
                'the diarizer returned no speaker segments',
            );
        }

        $segments = $this->recluster($wavPath, $segments);

        $aligned = $this->aligner->align(
            $tokens,
            $segments,
            $this->settings->diarization->boundaryToleranceMs,
        );

        $utterances = $aligned->utterances;
        $quality = $aligned->quality;

        if ($utterances === []) {
            // FAILED, not NEEDS_REVIEW — so it carries no review diagnosis. There is nothing for a
            // person to confirm here: not one word was placed with a speaker, so the screen offers no
            // roles to choose between and the status already says the stage did not produce a result.
            return SpeakerSeparatedTranscript::failed(
                $this->diarizer->method(),
                'no transcript token could be matched to a speaker segment',
            );
        }

        // The reason describes what was measured, rather than inferring a cause from it. An earlier
        // version reported every poorly-aligned recording as "heavy overlapping speech", which on the
        // reference call was exactly backwards: the diarizer had found no overlap at all, and the
        // tokens were sitting in the silences between its intervals.
        if ($quality->attributedShare < self::MIN_ATTRIBUTED_SHARE) {
            return SpeakerSeparatedTranscript::needsReview(
                $utterances,
                null,
                $this->diarizer->method(),
                'not enough speech could be attributed to a speaker: ' . $quality->describe(),
                SeparationReviewReason::LOW_ATTRIBUTED_SHARE,
            );
        }

        // Whether there were two speakers at all — asked before, and independently of, which one is the
        // agent. The role mapper can be perfectly confident about a cluster containing three words, so
        // its confidence is no evidence that the separation underneath it is usable. Publishing a split
        // requires both, and this is the half that was missing.
        $balance = SeparationBalance::of($utterances);

        if (!$balance->isUsable()) {
            return SpeakerSeparatedTranscript::needsReview(
                $utterances,
                // No role confidence is reported: role mapping is not run, because there is nothing
                // here worth mapping. A number in this column would invite exactly the reading that
                // caused the problem — that a confident score means a sound result.
                null,
                $this->diarizer->method(),
                'the recording could not be separated into two speakers: ' . $balance->describe(),
                SeparationReviewReason::UNUSABLE_SPEAKER_BALANCE,
            );
        }

        $mapping = $this->roleMapper->map($utterances);

        if ($mapping['reason'] !== null) {
            return SpeakerSeparatedTranscript::needsReview(
                $mapping['utterances'],
                $mapping['confidence'],
                $this->diarizer->method(),
                $mapping['reason'],
                // The mapper reports two distinct situations through one field, and they want different
                // answers: one voice cannot be helped by better role rules, two voices with nothing to
                // go on cannot be helped by better diarization. Its own code, not a match on its
                // sentence — which would make the wording load-bearing.
                $mapping['reasonCode'],
            );
        }

        if ($mapping['confidence'] < $this->settings->diarization->minConfidence) {
            return SpeakerSeparatedTranscript::needsReview(
                $mapping['utterances'],
                $mapping['confidence'],
                $this->diarizer->method(),
                sprintf(
                    'role confidence %.2f is below the %.2f threshold',
                    $mapping['confidence'],
                    $this->settings->diarization->minConfidence,
                ),
                SeparationReviewReason::ROLE_CONFIDENCE_LOW,
            );
        }

        $agentText = $this->textFor($mapping['utterances'], SpeakerRole::AGENT);
        $customerText = $this->textFor($mapping['utterances'], SpeakerRole::CUSTOMER);

        // A mapping that produced text for only one side is not a two-party split, whatever the score
        // said. Publishing it would present half a conversation as a complete one.
        if ($agentText === '' || $customerText === '') {
            return SpeakerSeparatedTranscript::needsReview(
                $mapping['utterances'],
                $mapping['confidence'],
                $this->diarizer->method(),
                'one of the two roles had no attributed speech',
                SeparationReviewReason::EMPTY_ROLE_TEXT,
            );
        }

        $this->logger->info('Speaker separation completed.', [
            'reason' => 'audio_speaker_separation_completed',
            'error_message' => $quality->describe(),
        ]);

        return SpeakerSeparatedTranscript::completed(
            $agentText,
            $customerText,
            $mapping['utterances'],
            $mapping['confidence'],
            $this->diarizer->method(),
        );
    }

    /**
     * One more clustering pass, and only for a result that was already lost.
     *
     * The clusterer is asked for a **fixed** number of speakers, so on a call whose two voices are
     * acoustically close it can spend its single bipartition separating the moments both people spoke at
     * once from everything else, leaving the two of them fused in one cluster. The signature of that is
     * exact rather than statistical, and {@see DegenerateSpeakerClusters} is where it is defined.
     *
     * Three properties make this safe to do, and they are the reason it is shaped the way it is:
     *
     * 1. **It can only touch a recording that was already going to NEEDS_REVIEW.** A cluster made only
     *    of shadowed intervals wins no tokens from the aligner, so the split it belongs to has one
     *    speaker and fails the balance gate. A recording that separates correctly does not match the
     *    predicate and is not diarized twice — the cost is one extra pass on a failure, not on a call.
     * 2. **Once, never twice.** There is no loop: one pass at one higher count, and whatever comes back
     *    is the last word. A recording that is degenerate at both counts keeps its first result.
     * 3. **The retry can only improve the answer or be discarded.** A second degenerate result, an
     *    empty one, or an exception all leave the first result in place, so asking again cannot turn a
     *    NEEDS_REVIEW into a FAILED.
     *
     * Nothing about which recordings publish changes here. A recovered recording reaches the role mapper
     * that the degenerate one never got to, and then meets the same unchanged confidence threshold; for
     * `22539329.wav` that means a correctly alternating two-party conversation an administrator can
     * confirm, not an automatic publication.
     *
     * @param list<SpeakerSegment> $segments
     *
     * @return list<SpeakerSegment>
     */
    private function recluster(string $wavPath, array $segments): array
    {
        if (!DegenerateSpeakerClusters::describes($segments)) {
            return $segments;
        }

        $asked = $this->settings->diarization->maxSpeakers + 1;

        try {
            $retried = $this->diarizer->diarize($wavPath, $asked);
        } catch (Throwable $e) {
            // The first result stands. It is a poor one, but it is a result, and the stage's contract is
            // that it never makes the outcome worse than what it already had.
            $this->logger->warning('Re-clustering a degenerate diarization failed; keeping the first result.', [
                'reason' => 'audio_diarization_recluster_failed',
                'error_message' => $e->getMessage(),
            ]);

            return $segments;
        }

        if ($retried === [] || DegenerateSpeakerClusters::describes($retried)) {
            $this->logger->info('Re-clustering a degenerate diarization did not separate the speakers.', [
                'reason' => 'audio_diarization_recluster_rejected',
                'error_message' => sprintf(
                    'asked for %d clusters and got %d segments; still degenerate',
                    $asked,
                    count($retried),
                ),
            ]);

            return $segments;
        }

        $this->logger->info('Re-clustered a degenerate diarization.', [
            'reason' => 'audio_diarization_reclustered',
            'error_message' => sprintf(
                'asked for %d clusters: %d segments became %d',
                $asked,
                count($segments),
                count($retried),
            ),
        ]);

        return $retried;
    }

    /**
     * Assembles one role's text: chronological, one utterance per line, nothing added or removed.
     *
     * @param list<SpeakerUtterance> $utterances
     */
    private function textFor(array $utterances, SpeakerRole $role): string
    {
        $lines = [];
        foreach ($utterances as $utterance) {
            if ($utterance->role === $role && trim($utterance->text) !== '') {
                $lines[] = trim($utterance->text);
            }
        }

        return implode("\n", $lines);
    }
}
