<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Application\Speaker\SpeakerRoleMapper;
use App\AudioToText\Application\Speaker\SpeakerSeparationService;
use App\AudioToText\Application\Speaker\SpeakerTranscriptAligner;
use App\Tests\Support\AudioToTextSettingsFactory;
use App\Tests\Support\Fake\AudioToText\RecordingDiarizer;
use App\AudioToText\Domain\AudioTranscriptionException;
use App\AudioToText\Domain\Speaker\SpeakerDiarizerInterface;
use App\AudioToText\Domain\Speaker\SeparationReviewReason;
use App\AudioToText\Domain\Speaker\SpeakerSegment;
use App\AudioToText\Domain\Speaker\TranscriptToken;
use App\AudioToText\Domain\SpeakerRole;
use App\AudioToText\Domain\SpeakerSeparationStatus;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

use function implode;
use function json_decode;
use function trim;

/**
 * The orchestrator's contract: **it returns an outcome and never throws.**
 *
 * By the time this runs the transcript is already committed to the database, so nothing here can put it
 * at risk. Every failure mode below therefore has to come back as a status the caller can store, not as
 * an exception that would unwind a job which has already produced a usable result.
 */
final class SpeakerSeparationServiceTest extends TestCase
{
    public function testDisabledDiarizationReportsNotSupported(): void
    {
        $result = $this->service($this->diarizer([]), enabled: false)->separate('/tmp/audio.wav', $this->tokens());

        $this->assertSame(SpeakerSeparationStatus::NOT_SUPPORTED, $result->status);
        $this->assertNull($result->agentText);
        $this->assertNull($result->customerText);
        $this->assertSame('none', $result->method);
    }

    public function testAnUnavailableToolchainReportsNotSupported(): void
    {
        $result = $this->service($this->diarizer([], available: false))->separate('/tmp/a.wav', $this->tokens());

        $this->assertSame(SpeakerSeparationStatus::NOT_SUPPORTED, $result->status);
    }

    /**
     * A diarizer that blows up must not become a failed transcription. The exception is swallowed into a
     * status and the technical detail goes to the log.
     */
    public function testADiarizerFailureIsCaughtAndReported(): void
    {
        $throwing = new class implements SpeakerDiarizerInterface {
            public function isAvailable(): bool
            {
                return true;
            }

            public function method(): string
            {
                return 'sherpa-onnx';
            }

            public function diarize(string $wavPath, ?int $maxSpeakers = null): array
            {
                throw AudioTranscriptionException::transcriptionTimedOut(300);
            }
        };

        $result = $this->service($throwing)->separate('/tmp/a.wav', $this->tokens());

        $this->assertSame(SpeakerSeparationStatus::FAILED, $result->status);
        $this->assertNull($result->agentText);
        $this->assertNotNull($result->reason);
    }

    public function testNoSegmentsIsAFailure(): void
    {
        $result = $this->service($this->diarizer([]))->separate('/tmp/a.wav', $this->tokens());

        $this->assertSame(SpeakerSeparationStatus::FAILED, $result->status);
        $this->assertStringContainsString('no speaker segments', (string) $result->reason);
    }

    /**
     * Without token timestamps there is nothing to align. The transcript is still fine — this is exactly
     * the graceful-degradation path.
     */
    public function testMissingTokensIsAFailureNotACrash(): void
    {
        $result = $this->service($this->diarizer($this->segments()))->separate('/tmp/a.wav', []);

        $this->assertSame(SpeakerSeparationStatus::FAILED, $result->status);
        $this->assertStringContainsString('no timestamped tokens', (string) $result->reason);
    }

    public function testACleanTwoSpeakerCallIsSeparated(): void
    {
        $result = $this->service($this->diarizer($this->segments()))->separate('/tmp/a.wav', $this->tokens());

        $this->assertSame(SpeakerSeparationStatus::COMPLETED, $result->status);
        $this->assertNotNull($result->agentText);
        $this->assertNotNull($result->customerText);
        $this->assertSame('sherpa-onnx', $result->method);

        // The order-bearing detail must survive verbatim — this is transcript text, not a summary.
        $this->assertStringContainsString('Apartment 1B', (string) $result->customerText);
        $this->assertStringContainsString('cash or card', (string) $result->agentText);
    }

    /** Structured segments are always written when diarization ran, so a mapping can be audited. */
    public function testSegmentsJsonCarriesNeutralSpeakerAndRole(): void
    {
        $result = $this->service($this->diarizer($this->segments()))->separate('/tmp/a.wav', $this->tokens());

        $decoded = json_decode((string) $result->segmentsJson(), true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('speaker', $decoded[0]);
        $this->assertArrayHasKey('role', $decoded[0]);
        $this->assertStringStartsWith('SPEAKER_', $decoded[0]['speaker']);
    }

    /**
     * One detectable voice is not a two-party call, and must not be forced into two columns.
     */
    public function testASingleSpeakerNeedsReviewAndFillsNoColumn(): void
    {
        $result = $this->service($this->diarizer([new SpeakerSegment(0, 20000, 'SPEAKER_00')]))
            ->separate('/tmp/a.wav', $this->tokens());

        $this->assertSame(SpeakerSeparationStatus::NEEDS_REVIEW, $result->status);
        $this->assertNull($result->agentText);
        $this->assertNull($result->customerText);
        // The utterances are still kept: someone reviewing a flagged call needs to see what was detected.
        $this->assertNotSame([], $result->utterances);
    }

    /**
     * A call the mapper could not orient stays unpublished, and says so.
     *
     * Both speakers here ask the order-taker's questions and neither answers them, so there is evidence
     * but no margin. The threshold is left at its real value: the point is that a genuinely ambiguous
     * call is refused, not that some number can be set high enough to refuse anything.
     */
    public function testAMappingBelowTheConfidenceThresholdNeedsReview(): void
    {
        $result = $this->service($this->diarizer($this->segments()))
            ->separate('/tmp/a.wav', $this->ambiguousTokens());

        $this->assertSame(SpeakerSeparationStatus::NEEDS_REVIEW, $result->status);
        $this->assertNull($result->agentText);
        $this->assertStringContainsString('below the', (string) $result->reason);
    }

    /**
     * The 21911549.wav failure, reproduced from its shape.
     *
     * Diarization gives one cluster nearly the whole call and the other a three-word fragment. Role
     * mapping is *perfectly* confident about that fragment — there is nothing to contradict it — and
     * before the separation gate existed, that confidence alone published the split.
     */
    public function testAConfidentRoleMappingOverAnUnusableSeparationNeedsReview(): void
    {
        $result = $this->service($this->diarizer([
            new SpeakerSegment(0, 37000, 'SPEAKER_00'),
            new SpeakerSegment(37100, 39800, 'SPEAKER_01'),
            new SpeakerSegment(39900, 172700, 'SPEAKER_00'),
        ]))->separate('/tmp/a.wav', [
            new TranscriptToken(100, 36900, ' Hello, would you like to place an order? Pickup or delivery? '
                . "What's the address? Cash or card? Anything else? Your total is \$27.75."),
            new TranscriptToken(37200, 39700, ' order? A large'),
            new TranscriptToken(40000, 172600, ' Delivery in 30 minutes, and how many orders of chicken '
                . 'wings would you like with that, and anything else at all today, thank you very much.'),
        ]);

        $this->assertSame(SpeakerSeparationStatus::NEEDS_REVIEW, $result->status);
        $this->assertNull($result->agentText);
        $this->assertNull($result->customerText);
        $this->assertStringContainsString('could not be separated into two speakers', (string) $result->reason);
        // Role confidence is not reported at all: the question was never reached.
        $this->assertNull($result->confidence);
        // The detected conversation is still kept for review.
        $this->assertNotSame([], $result->utterances);
    }

    /** A lopsided but genuine call still publishes — the gate rejects emptiness, not imbalance. */
    public function testALopsidedButGenuineCallStillCompletes(): void
    {
        $segments = [];
        $tokens = [];
        $at = 0;

        for ($i = 0; $i < 6; ++$i) {
            $segments[] = new SpeakerSegment($at, $at + 9000, 'SPEAKER_00');
            $tokens[] = new TranscriptToken($at + 100, $at + 8900, [
                ' Hello, would you like to place an order?',
                ' Is that for pickup or delivery?',
                " Okay, and what's the address there?",
                ' What would you like to order today?',
                ' Anything else with that at all?',
                ' Cash or card for that order?',
            ][$i]);
            $at += 9000;

            $segments[] = new SpeakerSegment($at, $at + 1600, 'SPEAKER_01');
            $tokens[] = new TranscriptToken($at + 100, $at + 1500, [
                ' Yes please.',
                ' Delivery.',
                ' 140 Main Street.',
                ' Sesame chicken.',
                " No, that's it.",
                ' Cash.',
            ][$i]);
            $at += 1600;
        }

        $result = $this->service($this->diarizer($segments))->separate('/tmp/a.wav', $tokens);

        $this->assertSame(SpeakerSeparationStatus::COMPLETED, $result->status);
        $this->assertNotNull($result->agentText);
        $this->assertNotNull($result->customerText);
    }

    /**
     * Whatever the split does, the transcript is not this stage's to lose — it is committed before this
     * runs, and every outcome here is a value rather than an exception.
     */
    public function testAnUnusableSeparationNeverThrows(): void
    {
        $result = $this->service($this->diarizer([
            new SpeakerSegment(0, 172700, 'SPEAKER_00'),
        ]))->separate('/tmp/a.wav', [
            new TranscriptToken(100, 172600, ' A single unbroken stretch of speech with nobody replying.'),
        ]);

        $this->assertSame(SpeakerSeparationStatus::NEEDS_REVIEW, $result->status);
        $this->assertNull($result->agentText);
    }

    /** Every published utterance reaches its column; nothing is silently dropped in aggregation. */
    public function testAggregationPreservesEveryPublishedUtterance(): void
    {
        $result = $this->service($this->diarizer($this->segments()))
            ->separate('/tmp/a.wav', $this->tokens());

        $this->assertSame(SpeakerSeparationStatus::COMPLETED, $result->status);

        $agentLines = [];
        $customerLines = [];
        foreach ($result->utterances as $utterance) {
            $text = trim($utterance->text);
            if ($text === '') {
                continue;
            }
            if ($utterance->role === SpeakerRole::AGENT) {
                $agentLines[] = $text;
            }
            if ($utterance->role === SpeakerRole::CUSTOMER) {
                $customerLines[] = $text;
            }
        }

        $this->assertNotSame([], $agentLines);
        $this->assertNotSame([], $customerLines);
        $this->assertSame(implode("\n", $agentLines), $result->agentText);
        $this->assertSame(implode("\n", $customerLines), $result->customerText);
    }

    // ------------------------------------------------ why a recording was left for a person

    /**
     * A published recording carries no diagnosis, because there is nothing to explain.
     *
     * The column answers "why was this left for review". A recording that was not left for review has
     * no answer to give, and inventing one would put a failure reason on a success.
     */
    public function testAPublishedSeparationCarriesNoReviewDiagnosis(): void
    {
        $result = $this->service($this->diarizer($this->segments()))->separate('/tmp/a.wav', $this->tokens());

        $this->assertSame(SpeakerSeparationStatus::COMPLETED, $result->status);
        $this->assertNull($result->reviewReason);
    }

    /**
     * One voice is diagnosed as a speaker problem, not a role problem.
     *
     * The distinction is the whole point of the column: better role rules cannot help a recording the
     * diarizer heard as one person, and counting this with the role failures would send effort to the
     * wrong place.
     */
    public function testOneVoiceIsDiagnosedAsAnUnusableSpeakerBalance(): void
    {
        $result = $this->service($this->diarizer([new SpeakerSegment(0, 20000, 'SPEAKER_00')]))
            ->separate('/tmp/a.wav', $this->tokens());

        $this->assertSame(SpeakerSeparationStatus::NEEDS_REVIEW, $result->status);
        $this->assertSame(SeparationReviewReason::UNUSABLE_SPEAKER_BALANCE, $result->reviewReason);
    }

    /**
     * Two voices and nothing to tell them apart is diagnosed as missing evidence.
     *
     * Both speakers here ask the order-taker's questions and neither answers them, so the mapper has
     * two speakers and no orientation — a different problem from having no second speaker at all.
     */
    public function testTwoVoicesWithNoOrientationAreDiagnosedAsMissingRoleSignals(): void
    {
        // Long enough on both sides to be a real two-party call — the balance gate runs first, and a
        // pair of one-word exchanges would be diagnosed as the speaker problem it genuinely is.
        $result = $this->service($this->diarizer($this->segments()))->separate('/tmp/a.wav', $this->neutralTokens());

        $this->assertSame(SpeakerSeparationStatus::NEEDS_REVIEW, $result->status);
        $this->assertSame(SeparationReviewReason::NO_ROLE_SIGNALS, $result->reviewReason);
    }

    /**
     * Evidence on both sides that does not clear the bar is diagnosed as low confidence.
     *
     * Reached by raising the threshold rather than by weakening the call, so the mapping the classifier
     * produced is identical to the one it produces today — only the bar moved.
     */
    public function testEvidenceBelowTheThresholdIsDiagnosedAsLowConfidence(): void
    {
        // A single quantity exchange and nothing else: oriented, so the mapper resolves it, but far
        // short of the evidence a full case carries. Run at the REAL threshold, so this is the gate as
        // it actually behaves rather than one bent to produce the outcome.
        $result = $this->service($this->diarizer($this->segments()))
            ->separate('/tmp/a.wav', $this->thinEvidenceTokens());

        $this->assertSame(SpeakerSeparationStatus::NEEDS_REVIEW, $result->status);
        $this->assertSame(SeparationReviewReason::ROLE_CONFIDENCE_LOW, $result->reviewReason);

        // And the confidence itself is untouched: the diagnosis records the outcome, never alters it.
        $this->assertNotNull($result->confidence);
        $this->assertGreaterThan(0.0, (float) $result->confidence);
    }

    /**
     * The diagnosis changes no decision, at any threshold.
     *
     * Run the same call twice at the two thresholds either side of it: the status and the confidence
     * are exactly what they were before this column existed, and only the explanation differs.
     */
    public function testTheDiagnosisNeverChangesTheOutcome(): void
    {
        // The same thin call at the two thresholds either side of its score.
        $published = $this->service($this->diarizer($this->segments()), minConfidence: 0.10)
            ->separate('/tmp/a.wav', $this->thinEvidenceTokens());
        $withheld = $this->service($this->diarizer($this->segments()), minConfidence: 0.55)
            ->separate('/tmp/a.wav', $this->thinEvidenceTokens());

        $this->assertSame(SpeakerSeparationStatus::COMPLETED, $published->status);
        $this->assertSame(SpeakerSeparationStatus::NEEDS_REVIEW, $withheld->status);
        $this->assertNull($published->reviewReason);
        $this->assertSame(SeparationReviewReason::ROLE_CONFIDENCE_LOW, $withheld->reviewReason);

        // Same call, same mapping, same score — the threshold is the only thing that moved.
        $this->assertSame($published->confidence, $withheld->confidence);
        $this->assertSame($published->segmentsJson(), $withheld->segmentsJson());
    }

    /**
     * A failure carries no review diagnosis either.
     *
     * FAILED already says in one word that the stage produced no result, and a recording in that state
     * offers a person nothing to confirm — there are no two sides to choose between.
     */
    public function testAFailureCarriesNoReviewDiagnosis(): void
    {
        $result = $this->service($this->diarizer([]))->separate('/tmp/a.wav', $this->tokens());

        $this->assertSame(SpeakerSeparationStatus::FAILED, $result->status);
        $this->assertNull($result->reviewReason);
    }

    /** Every reason has words for an administrator, and none of them leaks a field name. */
    public function testEveryReasonExplainsItselfWithoutJargon(): void
    {
        foreach (SeparationReviewReason::cases() as $reason) {
            $explanation = $reason->explanation();

            $this->assertNotSame('', $explanation);
            $this->assertMatchesRegularExpression('/\.$/', $explanation, 'It is a sentence.');

            foreach (['speaker_', 'NEEDS_REVIEW', 'confidence threshold', 'SPEAKER_0', 'null'] as $jargon) {
                $this->assertStringNotContainsString($jargon, $explanation, $reason->value);
            }
        }
    }

    /**
     * Two speakers talking at length about nothing the mapper can orient on.
     *
     * Long enough per side to clear the balance gate, so what fails is the role question and not the
     * speaker question — which is the distinction these diagnoses exist to draw.
     *
     * @return list<TranscriptToken>
     */
    private function neutralTokens(): array
    {
        return [
            new TranscriptToken(100, 2800, ' Well the weather has been really something this week I have to say.'),
            new TranscriptToken(3100, 5800, ' It certainly has been, I could hardly believe it myself yesterday.'),
            new TranscriptToken(6100, 8800, ' My cousin said much the same thing when she rang me on Tuesday.'),
            new TranscriptToken(9100, 11800, ' That sounds about right, she always did notice these things first.'),
            new TranscriptToken(12100, 14800, ' Anyway I should let you get on, it has been lovely catching up.'),
            new TranscriptToken(15100, 17800, ' Likewise, take care of yourself and speak again before too long.'),
        ];
    }

    /**
     * One oriented exchange, and otherwise nothing.
     *
     * A quantity question answered by the other speaker is the weakest pair the scoring recognises, so
     * the mapper resolves the call but with a fraction of a full case behind it.
     *
     * @return list<TranscriptToken>
     */
    /**
     * A degenerate two-cluster result is asked again, once, for one more cluster.
     *
     * The recording in the bug report: the diarizer's second cluster is nothing but the moments both
     * people spoke at once, so it wins no tokens, the split has one speaker, and the balance gate turns
     * it away. Given room for a third cluster it separates the two people instead — and the recording
     * arrives at the role mapper it never used to reach.
     */
    public function testADegenerateResultIsRediarizedOnceWithOneMoreCluster(): void
    {
        $diarizer = $this->retryingDiarizer($this->degenerateSegments(), $this->segments());

        $result = $this->service($diarizer)->separate('/tmp/a.wav', $this->tokens());

        $this->assertSame([null, 3], $diarizer->asked, 'The configured count, then one more. Twice, not more.');
        $this->assertSame(SpeakerSeparationStatus::COMPLETED, $result->status);
        $this->assertNotNull($result->agentText);
        $this->assertNotNull($result->customerText);
    }

    /**
     * A recording that separates correctly is diarized exactly once.
     *
     * This is the cost claim, and no assertion about the outcome would catch it: a service that always
     * ran a second pass would publish this call identically and double the CPU of the whole stage.
     */
    public function testAHealthyResultIsNotDiarizedTwice(): void
    {
        $diarizer = $this->diarizer($this->segments());

        $result = $this->service($diarizer)->separate('/tmp/a.wav', $this->tokens());

        $this->assertSame([null], $diarizer->asked);
        $this->assertSame(SpeakerSeparationStatus::COMPLETED, $result->status);
    }

    /**
     * Degenerate at both counts: asked twice, and the first answer is what it keeps.
     *
     * There is no third pass to find, because there is no loop — one retry is the whole policy. The
     * recording ends exactly where it ends today, with the reason it has today.
     */
    public function testARecordingThatStaysDegenerateIsNotAskedAThirdTime(): void
    {
        $diarizer = $this->retryingDiarizer($this->degenerateSegments(), $this->degenerateSegments());

        $result = $this->service($diarizer)->separate('/tmp/a.wav', $this->tokens());

        $this->assertSame([null, 3], $diarizer->asked, 'Two passes, and the second one is the last.');
        $this->assertSame(SpeakerSeparationStatus::NEEDS_REVIEW, $result->status);
        $this->assertSame(SeparationReviewReason::UNUSABLE_SPEAKER_BALANCE, $result->reviewReason);
    }

    /**
     * A genuinely single-voiced recording is left alone, and no second speaker is invented for it.
     *
     * A monologue, hold music, and a CALLER or CALLEE side uploaded on its own all arrive here with one
     * cluster. There is nothing shadowed to recognise, so nothing is retried; had it been retried, a
     * clusterer asked for two speakers would have produced a second one out of the same single voice.
     */
    public function testASingleVoicedRecordingIsNeitherRetriedNorRecovered(): void
    {
        $diarizer = $this->diarizer($this->oneSpeakerSegments());

        $result = $this->service($diarizer)->separate('/tmp/a.wav', $this->tokens());

        $this->assertSame([null], $diarizer->asked, 'One cluster is not this failure.');
        $this->assertSame(SpeakerSeparationStatus::NEEDS_REVIEW, $result->status);
        $this->assertNull($result->agentText);
        $this->assertNull($result->customerText);
    }

    /**
     * A retry that blows up leaves the first result standing.
     *
     * The stage's contract is that it never makes an outcome worse than the one it already had, and a
     * second diarization is a second chance to time out. This recording was heading for NEEDS_REVIEW
     * before the retry was attempted and still gets there — not FAILED.
     */
    public function testAFailedRetryKeepsTheFirstResult(): void
    {
        $degenerate = $this->degenerateSegments();

        $diarizer = new class ($degenerate) implements SpeakerDiarizerInterface {
            public int $calls = 0;

            /**
             * @param list<SpeakerSegment> $segments
             */
            public function __construct(private readonly array $segments) {}

            public function isAvailable(): bool
            {
                return true;
            }

            public function method(): string
            {
                return 'sherpa-onnx';
            }

            public function diarize(string $wavPath, ?int $maxSpeakers = null): array
            {
                ++$this->calls;

                if ($maxSpeakers !== null) {
                    throw AudioTranscriptionException::transcriptionTimedOut(300);
                }

                return $this->segments;
            }
        };

        $result = $this->service($diarizer)->separate('/tmp/a.wav', $this->tokens());

        $this->assertSame(2, $diarizer->calls);
        $this->assertSame(SpeakerSeparationStatus::NEEDS_REVIEW, $result->status);
        $this->assertSame(SeparationReviewReason::UNUSABLE_SPEAKER_BALANCE, $result->reviewReason);
    }

    /**
     * An empty retry result is discarded rather than treated as "no speaker segments".
     *
     * Returning nothing is a diarizer failure, and the first result is still a result. Promoting the
     * empty answer would turn this recording's NEEDS_REVIEW into FAILED, which is the one thing a
     * diagnostic retry must not be able to do.
     */
    public function testAnEmptyRetryResultIsDiscarded(): void
    {
        $diarizer = $this->retryingDiarizer($this->degenerateSegments(), []);

        $result = $this->service($diarizer)->separate('/tmp/a.wav', $this->tokens());

        $this->assertSame([null, 3], $diarizer->asked);
        $this->assertSame(SpeakerSeparationStatus::NEEDS_REVIEW, $result->status);
        $this->assertSame(SeparationReviewReason::UNUSABLE_SPEAKER_BALANCE, $result->reviewReason);
    }

    /**
     * The count asked for follows the configured one, rather than being the number three.
     *
     * A deployment that had raised `AUDIO_DIARIZATION_MAX_SPEAKERS` to three because of conference calls
     * would otherwise have its retry ask for the count it already used, which is not a retry at all.
     */
    public function testTheRetryAsksForOneMoreThanIsConfigured(): void
    {
        $diarizer = $this->retryingDiarizer($this->degenerateSegments(), $this->segments());

        $this->service($diarizer, maxSpeakers: 4)->separate('/tmp/a.wav', $this->tokens());

        $this->assertSame([null, 5], $diarizer->asked);
    }

    private function thinEvidenceTokens(): array
    {
        return [
            new TranscriptToken(100, 2800, ' And how many of those would you like me to put down for you?'),
            new TranscriptToken(3100, 5800, ' Two please.'),
            new TranscriptToken(6100, 8800, ' Right you are, I will get that written down for you now.'),
            new TranscriptToken(9100, 11800, ' Thank you very much indeed, that is really very kind of you.'),
            new TranscriptToken(12100, 14800, ' Not at all, it is no trouble whatsoever on a quiet morning.'),
            new TranscriptToken(15100, 17800, ' Well I appreciate it all the same, you have been very helpful.'),
        ];
    }

    private function service(
        SpeakerDiarizerInterface $diarizer,
        bool $enabled = true,
        float $minConfidence = 0.55,
        int $maxSpeakers = 2,
    ): SpeakerSeparationService {
        return new SpeakerSeparationService(
            $diarizer,
            new SpeakerTranscriptAligner(),
            new SpeakerRoleMapper(),
            AudioToTextSettingsFactory::create(
                minConfidence: $minConfidence,
                diarizationEnabled: $enabled,
                maxSpeakers: $maxSpeakers,
            ),
            new NullLogger(),
        );
    }

    /**
     * @param list<SpeakerSegment> $segments
     */
    private function diarizer(array $segments, bool $available = true): RecordingDiarizer
    {
        return new RecordingDiarizer([$segments], $available);
    }

    /**
     * A diarizer that answers differently the second time it is asked.
     *
     * Every call is recorded with the cluster count it was given, which is how the tests below can say
     * "asked once" or "asked twice, the second time for one more" rather than inferring it from the
     * outcome — two different retry policies can produce the same status and differ entirely in cost.
     *
     * @param list<SpeakerSegment> $first
     * @param list<SpeakerSegment> $then
     */
    private function retryingDiarizer(array $first, array $then): RecordingDiarizer
    {
        return new RecordingDiarizer([$first, $then]);
    }

    /**
     * Two clusters where the smaller one is nothing but intervals inside the larger one's turns.
     *
     * The shape `22539329.wav` comes back in, reduced to the fixture timeline: one long stretch of
     * speaker A with a couple of cross-talk fragments buried in it and no moment where the second voice
     * is heard alone. {@see \App\Tests\Unit\AudioToText\DegenerateSpeakerClustersTest} holds the real
     * 22-segment output; this is the same property at a size a service test can read.
     *
     * @return list<SpeakerSegment>
     */
    private function degenerateSegments(): array
    {
        return [
            new SpeakerSegment(0, 18000, 'SPEAKER_00'),
            new SpeakerSegment(5000, 5500, 'SPEAKER_01'),
            new SpeakerSegment(12000, 12400, 'SPEAKER_01'),
        ];
    }

    /**
     * One voice for the whole recording — a monologue, or a CALLER/CALLEE side uploaded on its own.
     *
     * @return list<SpeakerSegment>
     */
    private function oneSpeakerSegments(): array
    {
        return [
            new SpeakerSegment(0, 9000, 'SPEAKER_00'),
            new SpeakerSegment(9000, 18000, 'SPEAKER_00'),
        ];
    }

    /**
     * @return list<SpeakerSegment>
     */
    private function segments(): array
    {
        return [
            new SpeakerSegment(0, 3000, 'SPEAKER_00'),
            new SpeakerSegment(3000, 6000, 'SPEAKER_01'),
            new SpeakerSegment(6000, 9000, 'SPEAKER_00'),
            new SpeakerSegment(9000, 12000, 'SPEAKER_01'),
            new SpeakerSegment(12000, 15000, 'SPEAKER_00'),
            new SpeakerSegment(15000, 18000, 'SPEAKER_01'),
        ];
    }

    /**
     * @return list<TranscriptToken>
     */
    private function tokens(): array
    {
        return [
            new TranscriptToken(100, 2800, ' Would you like to place an order? Pickup or delivery?'),
            new TranscriptToken(3100, 5800, ' Delivery please.'),
            new TranscriptToken(6100, 8800, " Okay, what's the address?"),
            new TranscriptToken(9100, 11800, ' Tori Guales 3, Apartment 1B.'),
            new TranscriptToken(12100, 14800, ' Anything else? cash or card?'),
            new TranscriptToken(15100, 17800, ' Cash. Two orders of chicken wings. Thank you. Bye.'),
        ];
    }

    /**
     * Two voices both behaving like the order-taker, neither answering the other.
     *
     * Long enough on both sides to clear the separation-balance gate — the point of this fixture is the
     * *role confidence* branch, so the separation itself has to be sound and only the orientation
     * ambiguous. The two speakers say the same things, so the hypotheses cancel exactly.
     *
     * @return list<TranscriptToken>
     */
    private function ambiguousTokens(): array
    {
        return [
            new TranscriptToken(100, 2800, ' So would you like to pay with cash or card today?'),
            new TranscriptToken(3100, 5800, ' So would you like to pay with cash or card today?'),
            new TranscriptToken(6100, 8800, ' And is there anything else you would like to add?'),
            new TranscriptToken(9100, 11800, ' And is there anything else you would like to add?'),
            new TranscriptToken(12100, 14800, ' Is that going to be for pickup or delivery?'),
            new TranscriptToken(15100, 17800, ' Is that going to be for pickup or delivery?'),
        ];
    }
}
