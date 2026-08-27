<?php

declare(strict_types=1);

namespace App\AudioToText\Application\Speaker;

use App\AudioToText\Application\AudioToTextSettings;
use App\AudioToText\Domain\Speaker\SpeakerDiarizerInterface;
use App\AudioToText\Domain\Speaker\SeparationBalance;
use App\AudioToText\Domain\Speaker\SpeakerSeparatedTranscript;
use App\AudioToText\Domain\Speaker\SpeakerUtterance;
use App\AudioToText\Domain\Speaker\TranscriptToken;
use App\AudioToText\Domain\SpeakerRole;
use Psr\Log\LoggerInterface;
use Throwable;

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

        $aligned = $this->aligner->align(
            $tokens,
            $segments,
            $this->settings->diarization->boundaryToleranceMs,
        );

        $utterances = $aligned->utterances;
        $quality = $aligned->quality;

        if ($utterances === []) {
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
            );
        }

        $mapping = $this->roleMapper->map($utterances);

        if ($mapping['reason'] !== null) {
            return SpeakerSeparatedTranscript::needsReview(
                $mapping['utterances'],
                $mapping['confidence'],
                $this->diarizer->method(),
                $mapping['reason'],
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
