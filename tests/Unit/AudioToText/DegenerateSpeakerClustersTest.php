<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Domain\Speaker\DegenerateSpeakerClusters;
use App\AudioToText\Domain\Speaker\SpeakerSegment;
use PHPUnit\Framework\TestCase;

use function array_reverse;

/**
 * The one diarization result that is worth asking about twice.
 *
 * Every fixture below marked "measured" is the real output of the production diarizer — same script,
 * same models, same settings — on two real recordings: `22539329.wav`, the call that renders as one
 * mixed Speaker 1 block, and `22414839.wav`, a call of the same kind that separates correctly. They are
 * copied in verbatim rather than hand-built, because the property being tested is a property of what
 * sherpa-onnx actually does at a fixed cluster count, and a tidied fixture would be testing the tidying.
 *
 * @see DegenerateSpeakerClusters for why this shape means the recording is already lost
 */
final class DegenerateSpeakerClustersTest extends TestCase
{
    /**
     * Measured: `22539329.wav` at the configured two clusters — the recording that reported this bug.
     *
     * 22 segments, 64.1 s against 3.2 s. Every one of the four smaller-cluster segments sits inside a
     * larger-cluster segment, so there is not one moment in 79 seconds where the diarizer heard the
     * second voice on its own. That is cross-talk with a speaker label, not a second party.
     */
    public function testTheRecordingThatRendersAsOneMixedBlockIsDegenerate(): void
    {
        self::assertTrue(DegenerateSpeakerClusters::describes(self::failingAtTwoClusters()));
    }

    /**
     * Measured: `22414839.wav` at the same two clusters — a call that separates correctly today.
     *
     * 37 segments, 82.9 s against 30.3 s. Three of the twelve smaller-cluster segments are shadowed, and
     * the other nine are turns the speaker took by themselves, two of them over five seconds long. One
     * standalone turn is enough to disqualify the whole result, and this has nine.
     */
    public function testACallThatSeparatesCorrectlyIsNotDegenerate(): void
    {
        self::assertFalse(DegenerateSpeakerClusters::describes(self::goodAtTwoClusters()));
    }

    /**
     * Measured: `22539329.wav` at one cluster more — what the retry actually gets back.
     *
     * 31 segments, 42.5 % against 57.5 %, alternating. This is the test that makes the retry one-shot by
     * arithmetic as well as by control flow: the thing it produces does not satisfy the predicate that
     * produced it, so there is nothing for a second pass to trigger on.
     */
    public function testTheRetryResultForThatSameRecordingIsNotDegenerate(): void
    {
        self::assertFalse(DegenerateSpeakerClusters::describes(self::failingAtThreeClusters()));
    }

    /**
     * A single-voiced recording, and a CALLER or CALLEE side uploaded on its own, are left alone.
     *
     * There is no shadowed cluster to recognise and no second speaker to recover — asking again could
     * only invent one, which is the opposite of the point. These recordings keep exactly the outcome
     * they have today.
     */
    public function testOneClusterIsNotDegenerate(): void
    {
        self::assertFalse(DegenerateSpeakerClusters::describes([
            new SpeakerSegment(0, 9000, 'SPEAKER_00'),
            new SpeakerSegment(10000, 21000, 'SPEAKER_00'),
            new SpeakerSegment(22000, 30000, 'SPEAKER_00'),
        ]));
    }

    /** Nothing to describe, and one segment cannot shadow itself. */
    public function testNothingAndOneSegmentAreNotDegenerate(): void
    {
        self::assertFalse(DegenerateSpeakerClusters::describes([]));
        self::assertFalse(DegenerateSpeakerClusters::describes([new SpeakerSegment(0, 5000, 'SPEAKER_00')]));
    }

    /** Given room already, the clusterer's answer is its own; a third cluster is not this failure. */
    public function testThreeClustersAreNotDegenerate(): void
    {
        self::assertFalse(DegenerateSpeakerClusters::describes([
            new SpeakerSegment(0, 18000, 'SPEAKER_00'),
            new SpeakerSegment(4000, 4500, 'SPEAKER_01'),
            new SpeakerSegment(9000, 9400, 'SPEAKER_02'),
        ]));
    }

    /**
     * One standalone interval in the smaller cluster is enough, however short.
     *
     * The predicate asks whether the second voice was ever audible alone, not how much of it was. A
     * cluster with a genuine turn in it can win tokens from the aligner, so the recording is not in the
     * state this is looking for.
     */
    public function testOneStandaloneIntervalDisqualifiesTheWholeResult(): void
    {
        $shadowedOnly = [
            new SpeakerSegment(0, 18000, 'SPEAKER_00'),
            new SpeakerSegment(4000, 4500, 'SPEAKER_01'),
            new SpeakerSegment(9000, 9400, 'SPEAKER_01'),
        ];

        self::assertTrue(DegenerateSpeakerClusters::describes($shadowedOnly));

        $shadowedOnly[] = new SpeakerSegment(19000, 19300, 'SPEAKER_01');

        self::assertFalse(DegenerateSpeakerClusters::describes($shadowedOnly));
    }

    /**
     * A segment that merely overlaps a turn is not shadowed by it.
     *
     * The difference matters because it is the difference between a cluster the aligner can never give a
     * word to and one it can: a segment reaching past the end of the turn it overlaps has a stretch that
     * is its own, and a token landing there is attributed to it.
     */
    public function testPartialOverlapIsNotContainment(): void
    {
        self::assertFalse(DegenerateSpeakerClusters::describes([
            new SpeakerSegment(0, 10000, 'SPEAKER_00'),
            new SpeakerSegment(9000, 12000, 'SPEAKER_01'),
        ]));
    }

    /** Order is not part of the question; the diarizer sorts its output, and this does not rely on it. */
    public function testTheAnswerDoesNotDependOnSegmentOrder(): void
    {
        $segments = self::failingAtTwoClusters();
        self::assertTrue(DegenerateSpeakerClusters::describes(array_reverse($segments)));
    }

    /**
     * Two clusters of equal length name no smaller one.
     *
     * Reachable only as a coincidence of rounding, and it is not the failure being looked for either
     * way: this predicate is about a cluster that is the shadow of another, and neither of two clusters
     * of the same size is plausibly the shadow of the other.
     */
    public function testATieIsNotDegenerate(): void
    {
        self::assertFalse(DegenerateSpeakerClusters::describes([
            new SpeakerSegment(0, 10000, 'SPEAKER_00'),
            new SpeakerSegment(2000, 7000, 'SPEAKER_01'),
            new SpeakerSegment(8000, 13000, 'SPEAKER_01'),
        ]));
    }

    /**
     * Measured — `22539329.wav`, production diarizer, `--max-speakers 2`.
     *
     * @return list<SpeakerSegment>
     */
    private static function failingAtTwoClusters(): array
    {
        return [
            new SpeakerSegment(875, 2292, 'SPEAKER_00'),
            new SpeakerSegment(2917, 4537, 'SPEAKER_00'),
            new SpeakerSegment(5093, 6511, 'SPEAKER_00'),
            new SpeakerSegment(7034, 7709, 'SPEAKER_00'),
            new SpeakerSegment(9177, 14813, 'SPEAKER_00'),
            new SpeakerSegment(15877, 19673, 'SPEAKER_00'),
            new SpeakerSegment(18020, 19150, 'SPEAKER_01'),
            new SpeakerSegment(20433, 21344, 'SPEAKER_00'),
            new SpeakerSegment(21007, 21310, 'SPEAKER_01'),
            new SpeakerSegment(21901, 32077, 'SPEAKER_00'),
            new SpeakerSegment(33106, 36312, 'SPEAKER_00'),
            new SpeakerSegment(34118, 35080, 'SPEAKER_01'),
            new SpeakerSegment(36903, 40328, 'SPEAKER_00'),
            new SpeakerSegment(40852, 46285, 'SPEAKER_00'),
            new SpeakerSegment(46792, 56393, 'SPEAKER_00'),
            new SpeakerSegment(57119, 59482, 'SPEAKER_00'),
            new SpeakerSegment(60646, 66350, 'SPEAKER_00'),
            new SpeakerSegment(67008, 71243, 'SPEAKER_00'),
            new SpeakerSegment(71986, 73825, 'SPEAKER_00'),
            new SpeakerSegment(72188, 72965, 'SPEAKER_01'),
            new SpeakerSegment(74399, 76390, 'SPEAKER_00'),
            new SpeakerSegment(77909, 78601, 'SPEAKER_00'),
        ];
    }

    /**
     * Measured — `22539329.wav`, production diarizer, `--max-speakers 3`.
     *
     * @return list<SpeakerSegment>
     */
    private static function failingAtThreeClusters(): array
    {
        return [
            new SpeakerSegment(875, 2292, 'SPEAKER_01'),
            new SpeakerSegment(2917, 4537, 'SPEAKER_00'),
            new SpeakerSegment(5093, 6511, 'SPEAKER_01'),
            new SpeakerSegment(7034, 7709, 'SPEAKER_00'),
            new SpeakerSegment(9177, 9852, 'SPEAKER_00'),
            new SpeakerSegment(9852, 11759, 'SPEAKER_01'),
            new SpeakerSegment(11472, 14813, 'SPEAKER_00'),
            new SpeakerSegment(15877, 19673, 'SPEAKER_01'),
            new SpeakerSegment(18020, 19150, 'SPEAKER_00'),
            new SpeakerSegment(20433, 21310, 'SPEAKER_01'),
            new SpeakerSegment(21007, 21344, 'SPEAKER_00'),
            new SpeakerSegment(21901, 27554, 'SPEAKER_00'),
            new SpeakerSegment(27858, 29005, 'SPEAKER_01'),
            new SpeakerSegment(29005, 32077, 'SPEAKER_00'),
            new SpeakerSegment(33106, 36312, 'SPEAKER_01'),
            new SpeakerSegment(34118, 35080, 'SPEAKER_00'),
            new SpeakerSegment(36903, 40328, 'SPEAKER_00'),
            new SpeakerSegment(40852, 43012, 'SPEAKER_01'),
            new SpeakerSegment(43417, 44362, 'SPEAKER_00'),
            new SpeakerSegment(44480, 44935, 'SPEAKER_01'),
            new SpeakerSegment(44935, 46285, 'SPEAKER_00'),
            new SpeakerSegment(46792, 56393, 'SPEAKER_01'),
            new SpeakerSegment(57119, 59482, 'SPEAKER_00'),
            new SpeakerSegment(60646, 65658, 'SPEAKER_01'),
            new SpeakerSegment(65540, 66350, 'SPEAKER_00'),
            new SpeakerSegment(67008, 71243, 'SPEAKER_01'),
            new SpeakerSegment(71986, 73825, 'SPEAKER_00'),
            new SpeakerSegment(72020, 72965, 'SPEAKER_01'),
            new SpeakerSegment(74399, 76019, 'SPEAKER_01'),
            new SpeakerSegment(76087, 76390, 'SPEAKER_00'),
            new SpeakerSegment(77909, 78601, 'SPEAKER_01'),
        ];
    }

    /**
     * Measured — `22414839.wav`, production diarizer, `--max-speakers 2`.
     *
     * @return list<SpeakerSegment>
     */
    private static function goodAtTwoClusters(): array
    {
        return [
            new SpeakerSegment(1027, 3153, 'SPEAKER_01'),
            new SpeakerSegment(3912, 5245, 'SPEAKER_00'),
            new SpeakerSegment(6292, 8165, 'SPEAKER_01'),
            new SpeakerSegment(9481, 14594, 'SPEAKER_00'),
            new SpeakerSegment(16552, 19150, 'SPEAKER_01'),
            new SpeakerSegment(20416, 21428, 'SPEAKER_00'),
            new SpeakerSegment(22576, 24382, 'SPEAKER_01'),
            new SpeakerSegment(23015, 23470, 'SPEAKER_00'),
            new SpeakerSegment(24922, 25968, 'SPEAKER_01'),
            new SpeakerSegment(26592, 37004, 'SPEAKER_01'),
            new SpeakerSegment(35705, 36532, 'SPEAKER_00'),
            new SpeakerSegment(37696, 38303, 'SPEAKER_01'),
            new SpeakerSegment(39248, 40075, 'SPEAKER_01'),
            new SpeakerSegment(40649, 44007, 'SPEAKER_01'),
            new SpeakerSegment(45290, 47095, 'SPEAKER_00'),
            new SpeakerSegment(48125, 48698, 'SPEAKER_01'),
            new SpeakerSegment(48698, 49205, 'SPEAKER_00'),
            new SpeakerSegment(49441, 51415, 'SPEAKER_01'),
            new SpeakerSegment(52799, 53947, 'SPEAKER_00'),
            new SpeakerSegment(55111, 62502, 'SPEAKER_01'),
            new SpeakerSegment(63363, 67852, 'SPEAKER_00'),
            new SpeakerSegment(66721, 67210, 'SPEAKER_01'),
            new SpeakerSegment(67852, 69067, 'SPEAKER_01'),
            new SpeakerSegment(69877, 77538, 'SPEAKER_00'),
            new SpeakerSegment(78466, 84997, 'SPEAKER_01'),
            new SpeakerSegment(85992, 91274, 'SPEAKER_00'),
            new SpeakerSegment(92236, 95105, 'SPEAKER_01'),
            new SpeakerSegment(94193, 94835, 'SPEAKER_00'),
            new SpeakerSegment(96050, 96421, 'SPEAKER_01'),
            new SpeakerSegment(96961, 97822, 'SPEAKER_01'),
            new SpeakerSegment(98378, 102547, 'SPEAKER_01'),
            new SpeakerSegment(103407, 103745, 'SPEAKER_01'),
            new SpeakerSegment(105230, 110528, 'SPEAKER_01'),
            new SpeakerSegment(112233, 113110, 'SPEAKER_01'),
            new SpeakerSegment(113903, 114612, 'SPEAKER_01'),
            new SpeakerSegment(115574, 133276, 'SPEAKER_01'),
            new SpeakerSegment(133782, 140667, 'SPEAKER_01'),
        ];
    }
}
