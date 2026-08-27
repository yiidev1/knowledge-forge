<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Application\Speaker\SpeakerTranscriptAligner;
use App\AudioToText\Domain\Speaker\SpeakerSegment;
use App\AudioToText\Domain\Speaker\SpeakerUtterance;
use App\AudioToText\Domain\Speaker\TranscriptToken;
use App\AudioToText\Domain\SpeakerRole;
use PHPUnit\Framework\TestCase;

use function array_map;
use function str_contains;
use function implode;
use function preg_replace;
use function trim;

/**
 * Alignment, driven entirely by hand-built timestamps.
 *
 * No audio, no model, no diarizer — the property under test is arithmetic, and making it depend on a
 * real recording would make it slow and untestable without making it any more correct.
 *
 * Several cases below are drawn directly from a real failure. On a 71-second restaurant call the
 * diarizer covered only 55.9% of the audio across 22 intervals, leaving 21 gaps (median 1.2s, longest
 * 3.2s) and **zero** genuine cross-speaker overlap. Matching on overlap alone stranded a third of the
 * tokens in those gaps, and the result was reported as "heavy overlapping speech" — the opposite of
 * what the data showed.
 */
final class SpeakerTranscriptAlignerTest extends TestCase
{
    private const TOLERANCE = 1500;

    private SpeakerTranscriptAligner $aligner;

    protected function setUp(): void
    {
        $this->aligner = new SpeakerTranscriptAligner();
    }

    public function testTokensAreAssignedToTheOverlappingSpeaker(): void
    {
        $result = $this->aligner->align(
            [
                new TranscriptToken(0, 500, ' Hello'),
                new TranscriptToken(500, 900, '?'),
                new TranscriptToken(1100, 1600, ' Hello'),
                new TranscriptToken(1600, 1800, '.'),
            ],
            [
                new SpeakerSegment(0, 1000, 'SPEAKER_00'),
                new SpeakerSegment(1000, 2000, 'SPEAKER_01'),
            ],
            self::TOLERANCE,
        );

        $this->assertCount(2, $result->utterances);
        $this->assertSame('SPEAKER_00', $result->utterances[0]->speaker);
        $this->assertSame('Hello?', $result->utterances[0]->text);
        $this->assertSame('SPEAKER_01', $result->utterances[1]->speaker);
        $this->assertSame('Hello.', $result->utterances[1]->text);
    }

    /**
     * The measured reason token-level alignment is required: whisper emits segments many seconds long
     * containing several speaker turns, so segment-level matching collapses a conversation into one
     * speaker.
     */
    public function testManyTurnsInsideOneWhisperSegmentAreStillSeparated(): void
    {
        $result = $this->aligner->align(
            [
                new TranscriptToken(0, 400, ' Pickup'),
                new TranscriptToken(400, 900, ' or delivery?'),
                new TranscriptToken(1000, 1400, ' Delivery'),
                new TranscriptToken(1400, 1500, '.'),
                new TranscriptToken(2000, 2400, ' Address?'),
                new TranscriptToken(3000, 3600, ' Apartment 1B'),
            ],
            [
                new SpeakerSegment(0, 950, 'SPEAKER_00'),
                new SpeakerSegment(950, 1900, 'SPEAKER_01'),
                new SpeakerSegment(1900, 2900, 'SPEAKER_00'),
                new SpeakerSegment(2900, 4000, 'SPEAKER_01'),
            ],
            self::TOLERANCE,
        );

        $this->assertSame(
            ['SPEAKER_00', 'SPEAKER_01', 'SPEAKER_00', 'SPEAKER_01'],
            array_map(static fn(SpeakerUtterance $u): string => $u->speaker, $result->utterances),
        );
    }

    // ------------------------------------------------------------------ gap handling

    /**
     * The failure this whole change came from: a token sitting in a pause the diarizer did not mark.
     *
     * Without bridging it matched nothing, scored 0.0, and counted as evidence of overlapping speech.
     */
    public function testATokenInAGapIsAttributedToTheNearestSpeaker(): void
    {
        $result = $this->aligner->align(
            [new TranscriptToken(1100, 1400, ' Yes')],
            [
                new SpeakerSegment(0, 1000, 'SPEAKER_00'),
                new SpeakerSegment(2000, 3000, 'SPEAKER_01'),
            ],
            self::TOLERANCE,
        );

        $this->assertCount(1, $result->utterances);
        $this->assertSame('SPEAKER_00', $result->utterances[0]->speaker, 'Nearest by midpoint.');
        $this->assertSame(1.0, $result->quality->attributedShare);
        $this->assertGreaterThan(0.0, $result->quality->bridgedShare);
    }

    /**
     * A pause *within* one speaker's turn is not ambiguous at all, so it is bridged however long it is.
     * The tolerance exists for choosing between two different speakers, not for this.
     */
    public function testALongPauseInsideOneSpeakersTurnIsAlwaysBridged(): void
    {
        $result = $this->aligner->align(
            [new TranscriptToken(5000, 5400, ' erm')],
            [
                new SpeakerSegment(0, 1000, 'SPEAKER_00'),
                new SpeakerSegment(9000, 10000, 'SPEAKER_00'),
            ],
            self::TOLERANCE,
        );

        $this->assertSame('SPEAKER_00', $result->utterances[0]->speaker);
        $this->assertSame(1.0, $result->quality->attributedShare);
    }

    /** Just inside the tolerance: attributed. */
    public function testATokenJustInsideTheToleranceIsAttributed(): void
    {
        $result = $this->aligner->align(
            [new TranscriptToken(2300, 2500, ' ok')],
            [
                new SpeakerSegment(0, 1000, 'SPEAKER_00'),
                new SpeakerSegment(9000, 9500, 'SPEAKER_01'),
            ],
            self::TOLERANCE,
        );

        $this->assertSame('SPEAKER_00', $result->utterances[0]->speaker);
    }

    /** Beyond it: left unattributed rather than guessed at. */
    public function testATokenBeyondTheToleranceIsLeftUnattributed(): void
    {
        $result = $this->aligner->align(
            [new TranscriptToken(20000, 20400, ' stray')],
            [
                new SpeakerSegment(0, 1000, 'SPEAKER_00'),
                new SpeakerSegment(40000, 41000, 'SPEAKER_01'),
            ],
            self::TOLERANCE,
        );

        $this->assertSame(SpeakerRole::UNKNOWN->value, $result->utterances[0]->speaker);
        $this->assertSame(0.0, $result->quality->attributedShare);
        // Kept, not dropped: losing words silently would be the worst failure available here.
        $this->assertSame('stray', $result->utterances[0]->text);
    }

    /** A zero tolerance restores the old overlap-only behaviour, which is what makes it configurable. */
    public function testAZeroToleranceDisablesBridging(): void
    {
        $tokens = [new TranscriptToken(1100, 1400, ' Yes')];
        $segments = [
            new SpeakerSegment(0, 1000, 'SPEAKER_00'),
            new SpeakerSegment(2000, 3000, 'SPEAKER_01'),
        ];

        $this->assertSame(
            SpeakerRole::UNKNOWN->value,
            $this->aligner->align($tokens, $segments, 0)->utterances[0]->speaker,
        );
        $this->assertSame(
            'SPEAKER_00',
            $this->aligner->align($tokens, $segments, self::TOLERANCE)->utterances[0]->speaker,
        );
    }

    // ------------------------------------------------------------------ boundaries

    /**
     * A token straddling a turn change follows where most of it lies, and must not make the whole
     * utterance look unreliable.
     */
    public function testAStraddlingTokenFollowsItsMidpoint(): void
    {
        $late = $this->aligner->align(
            [new TranscriptToken(900, 1400, ' word')],
            [
                new SpeakerSegment(0, 1000, 'SPEAKER_00'),
                new SpeakerSegment(1000, 2000, 'SPEAKER_01'),
            ],
            self::TOLERANCE,
        );

        $this->assertSame('SPEAKER_01', $late->utterances[0]->speaker, 'Most of the token is after 1000ms.');

        $early = $this->aligner->align(
            [new TranscriptToken(600, 1100, ' word')],
            [
                new SpeakerSegment(0, 1000, 'SPEAKER_00'),
                new SpeakerSegment(1000, 2000, 'SPEAKER_01'),
            ],
            self::TOLERANCE,
        );

        $this->assertSame('SPEAKER_00', $early->utterances[0]->speaker);
    }

    public function testATokenOnTheBoundaryIsAssignedConsistently(): void
    {
        $result = $this->aligner->align(
            [new TranscriptToken(1000, 1000, ' Yes')],
            [
                new SpeakerSegment(0, 1000, 'SPEAKER_00'),
                new SpeakerSegment(1000, 2000, 'SPEAKER_01'),
            ],
            self::TOLERANCE,
        );

        $this->assertCount(1, $result->utterances);
        $this->assertSame('SPEAKER_01', $result->utterances[0]->speaker);
    }

    /**
     * A word split across a turn boundary must not be split across two roles.
     *
     * Whisper emits sub-word tokens, so "Sesame" arrives as " -S" + "esame". On the reference call the
     * boundary fell between them and the agent was credited with "-S" while the customer got "esame
     * chicken, dinner combo" — and the price "19.82" was split the same way.
     */
    public function testAWordIsNeverSplitAcrossTwoSpeakers(): void
    {
        $result = $this->aligner->align(
            [
                new TranscriptToken(0, 900, ' Order'),
                // The turn changes at 1000ms, mid-word.
                new TranscriptToken(950, 1050, ' Ses'),
                new TranscriptToken(1050, 1400, 'ame'),
                new TranscriptToken(1400, 1800, ' chicken'),
            ],
            [
                new SpeakerSegment(0, 1000, 'SPEAKER_00'),
                new SpeakerSegment(1000, 2000, 'SPEAKER_01'),
            ],
            self::TOLERANCE,
        );

        $text = implode(' | ', array_map(
            static fn(SpeakerUtterance $u): string => $u->speaker . ':' . $u->text,
            $result->utterances,
        ));

        $this->assertStringNotContainsString('Ses |', $text, '"Sesame" must not be torn in half.');

        foreach ($result->utterances as $utterance) {
            if (str_contains($utterance->text, 'Ses')) {
                $this->assertStringContainsString(
                    'Sesame',
                    $utterance->text,
                    'The continuation must stay with the token it continues.',
                );
            }
        }
    }

    /** Trailing punctuation belongs to the word it follows, not to whoever spoke next. */
    public function testTrailingPunctuationStaysWithItsWord(): void
    {
        $result = $this->aligner->align(
            [
                new TranscriptToken(0, 950, ' Delivery'),
                new TranscriptToken(990, 1100, '?'),
                new TranscriptToken(1200, 1600, ' Yes'),
            ],
            [
                new SpeakerSegment(0, 1000, 'SPEAKER_00'),
                new SpeakerSegment(1000, 2000, 'SPEAKER_01'),
            ],
            self::TOLERANCE,
        );

        $this->assertSame('Delivery?', $result->utterances[0]->text);
        $this->assertSame('SPEAKER_00', $result->utterances[0]->speaker);
    }

    // ------------------------------------------------------------------ quality reporting

    /**
     * Genuine simultaneous speech is measured from overlapping diarization intervals, and is the only
     * thing reported as such. Gaps are the opposite condition and must not be described this way.
     */
    public function testGenuineOverlapIsMeasuredFromOverlappingIntervals(): void
    {
        $overlapping = $this->aligner->align(
            [new TranscriptToken(0, 1000, ' both')],
            [
                new SpeakerSegment(0, 2000, 'SPEAKER_00'),
                new SpeakerSegment(1000, 2000, 'SPEAKER_01'),
            ],
            self::TOLERANCE,
        );

        $this->assertGreaterThan(0.0, $overlapping->quality->overlappingShare);
        $this->assertStringContainsString('talking at once', $overlapping->quality->describe());

        $gapped = $this->aligner->align(
            [new TranscriptToken(1100, 1400, ' word')],
            [
                new SpeakerSegment(0, 1000, 'SPEAKER_00'),
                new SpeakerSegment(3000, 4000, 'SPEAKER_00'),
            ],
            self::TOLERANCE,
        );

        $this->assertSame(0.0, $gapped->quality->overlappingShare);
        $this->assertStringNotContainsString('talking at once', $gapped->quality->describe());
    }

    /**
     * Quality is weighted by duration, so a handful of one-word fragments cannot outvote the bulk of
     * the conversation — the precise defect that produced "51% weak" on a recording where 70% of the
     * *speech* was cleanly attributed.
     */
    public function testQualityIsWeightedByDurationNotUtteranceCount(): void
    {
        $tokens = [new TranscriptToken(0, 20000, ' a long stretch of clearly attributed speech')];

        // Eight tiny stranded fragments, far outside any interval.
        for ($i = 0; $i < 8; ++$i) {
            $tokens[] = new TranscriptToken(60000 + ($i * 100), 60000 + ($i * 100) + 50, ' x');
        }

        $result = $this->aligner->align(
            $tokens,
            [new SpeakerSegment(0, 20000, 'SPEAKER_00')],
            self::TOLERANCE,
        );

        // Eight of nine tokens are unattributed — but they are 0.4 of 20.4 seconds of speech.
        $this->assertGreaterThan(
            0.95,
            $result->quality->attributedShare,
            'Tiny fragments must not dominate a duration-weighted measure.',
        );
    }

    // ------------------------------------------------------------------ invariants

    public function testChronologicalOrderIsPreserved(): void
    {
        $result = $this->aligner->align(
            [
                new TranscriptToken(0, 400, ' One'),
                new TranscriptToken(1000, 1400, ' Two'),
                new TranscriptToken(2000, 2400, ' Three'),
            ],
            [
                new SpeakerSegment(0, 900, 'SPEAKER_00'),
                new SpeakerSegment(900, 1900, 'SPEAKER_01'),
                new SpeakerSegment(1900, 3000, 'SPEAKER_00'),
            ],
            self::TOLERANCE,
        );

        $starts = array_map(static fn(SpeakerUtterance $u): int => $u->startMs, $result->utterances);
        $sorted = $starts;
        sort($sorted);

        $this->assertSame($sorted, $starts);
    }

    /** Losing words silently would be the worst failure mode available here. */
    public function testNoTokenIsLostOrDuplicated(): void
    {
        $tokens = [
            new TranscriptToken(0, 300, ' Two'),
            new TranscriptToken(300, 700, ' orders'),
            new TranscriptToken(700, 1200, ' of chicken wings'),
            new TranscriptToken(1300, 1700, ' Correct'),
            new TranscriptToken(1700, 1900, '.'),
        ];

        $result = $this->aligner->align($tokens, [
            new SpeakerSegment(0, 1250, 'SPEAKER_01'),
            new SpeakerSegment(1250, 2000, 'SPEAKER_00'),
        ], self::TOLERANCE);

        $expected = $this->flatten(implode('', array_map(
            static fn(TranscriptToken $t): string => $t->text,
            $tokens,
        )));

        $actual = $this->flatten(implode(' ', array_map(
            static fn(SpeakerUtterance $u): string => $u->text,
            $result->utterances,
        )));

        $this->assertSame($expected, $actual);
    }

    /** Even across gaps, and even when some tokens end up unattributed. */
    public function testNoTokenIsLostAcrossGaps(): void
    {
        $tokens = [
            new TranscriptToken(0, 500, ' Alpha'),
            new TranscriptToken(1200, 1600, ' Bravo'),
            new TranscriptToken(30000, 30400, ' Charlie'),
        ];

        $result = $this->aligner->align($tokens, [
            new SpeakerSegment(0, 1000, 'SPEAKER_00'),
            new SpeakerSegment(2000, 2500, 'SPEAKER_01'),
        ], self::TOLERANCE);

        $text = $this->flatten(implode(' ', array_map(
            static fn(SpeakerUtterance $u): string => $u->text,
            $result->utterances,
        )));

        foreach (['Alpha', 'Bravo', 'Charlie'] as $word) {
            $this->assertStringContainsString($word, $text);
        }
    }

    public function testNoSegmentsYieldsNoUtterances(): void
    {
        $result = $this->aligner->align([new TranscriptToken(0, 400, ' Hello')], [], self::TOLERANCE);

        $this->assertSame([], $result->utterances);
    }

    public function testNoTokensYieldsNoUtterances(): void
    {
        $result = $this->aligner->align([], [new SpeakerSegment(0, 1000, 'SPEAKER_00')], self::TOLERANCE);

        $this->assertSame([], $result->utterances);
    }

    /**
     * Whisper emits two shapes of control token: `[_BEG_]` and timestamp markers like `[_TT_390]`. An
     * earlier pattern required a trailing underscore and so let nine `[_TT_nnn]` markers leak into the
     * aligned text of the reference call.
     *
     * @dataProvider controlTokenProvider
     */
    public function testWhisperControlTokensAreStripped(string $marker): void
    {
        $result = $this->aligner->align(
            [
                new TranscriptToken(0, 0, $marker),
                new TranscriptToken(0, 400, ' Hello'),
            ],
            [new SpeakerSegment(0, 1000, 'SPEAKER_00')],
            self::TOLERANCE,
        );

        $this->assertCount(1, $result->utterances);
        $this->assertSame('Hello', $result->utterances[0]->text);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function controlTokenProvider(): array
    {
        return [
            'begin marker' => ['[_BEG_]'],
            'timestamp marker' => ['[_TT_390]'],
            'four-digit timestamp' => ['[_TT_1440]'],
        ];
    }

    private function flatten(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
