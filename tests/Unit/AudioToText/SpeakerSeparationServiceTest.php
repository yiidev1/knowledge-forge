<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Application\Speaker\SpeakerRoleMapper;
use App\AudioToText\Application\Speaker\SpeakerSeparationService;
use App\AudioToText\Application\Speaker\SpeakerTranscriptAligner;
use App\Tests\Support\AudioToTextSettingsFactory;
use App\AudioToText\Domain\AudioTranscriptionException;
use App\AudioToText\Domain\Speaker\SpeakerDiarizerInterface;
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

            public function diarize(string $wavPath): array
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

    private function service(
        SpeakerDiarizerInterface $diarizer,
        bool $enabled = true,
        float $minConfidence = 0.55,
    ): SpeakerSeparationService {
        return new SpeakerSeparationService(
            $diarizer,
            new SpeakerTranscriptAligner(),
            new SpeakerRoleMapper(),
            AudioToTextSettingsFactory::create(
                diarizationEnabled: $enabled,
                minConfidence: $minConfidence,
            ),
            new NullLogger(),
        );
    }

    /**
     * @param list<SpeakerSegment> $segments
     */
    private function diarizer(array $segments, bool $available = true): SpeakerDiarizerInterface
    {
        return new class ($segments, $available) implements SpeakerDiarizerInterface {
            /**
             * @param list<SpeakerSegment> $segments
             */
            public function __construct(
                private readonly array $segments,
                private readonly bool $available,
            ) {}

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function method(): string
            {
                return 'sherpa-onnx';
            }

            public function diarize(string $wavPath): array
            {
                return $this->segments;
            }
        };
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
