<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Application\Settings\UtteranceSettings;
use App\AudioToText\Application\Speaker\SingleSpeakerUtteranceSegmenter;
use App\AudioToText\Domain\Speaker\TranscriptToken;
use App\AudioToText\Domain\SpeakerRole;
use Codeception\Test\Unit;

/**
 * Cutting one speaker's words into utterances. **No test here runs a model or touches a database.**
 *
 * The rule is "split on measured silence", and the tests that matter are the ones pinning what must
 * *not* split: a sentence with a breath in it, a stream whose timings are unusable, and punctuation
 * with no pause after it.
 */
final class SingleSpeakerUtteranceSegmenterTest extends Unit
{
    private const GAP = 900;
    private const MAX = 20000;
    private const MIN = 1200;

    public function testOneContinuousSentenceStaysOneUtterance(): void
    {
        $utterances = $this->segment([
            [0, 400, ' Can'], [400, 800, ' I'], [800, 1600, ' get'], [1600, 2400, ' delivery?'],
        ]);

        $this->assertCount(1, $utterances);
        $this->assertSame('Can I get delivery?', $utterances[0]->text);
        $this->assertSame(0, $utterances[0]->startMs);
        $this->assertSame(2400, $utterances[0]->endMs);
    }

    /** The headline case: silence while the other party speaks ends the turn. */
    public function testALargeGapStartsANewUtterance(): void
    {
        $utterances = $this->segment([
            [0, 1500, ' Can I get delivery?'],
            [6000, 7500, ' 107 Craig Avenue.'],
            [12000, 13500, ' Vegetable fried rice.'],
        ]);

        $this->assertCount(3, $utterances);
        $this->assertSame('Can I get delivery?', $utterances[0]->text);
        $this->assertSame('107 Craig Avenue.', $utterances[1]->text);
        $this->assertSame(12000, $utterances[2]->startMs);
    }

    public function testAGapBelowTheThresholdDoesNotSplit(): void
    {
        // 899 ms — one short of the boundary.
        $this->assertCount(1, $this->segment([[0, 1500, ' Yes,'], [2399, 3600, ' please.']]));
    }

    /** `>=`, so the threshold itself splits. Pinned because an off-by-one here is invisible. */
    public function testAGapExactlyAtTheThresholdSplits(): void
    {
        $this->assertCount(2, $this->segment([[0, 1500, ' Yes.'], [2400, 3900, ' Please.']]));
    }

    /**
     * **Punctuation alone never splits.** The sample contained sentence endings with no pause after
     * them; splitting on those would cut a speaker mid-flow.
     */
    public function testPunctuationWithoutATimeGapDoesNotSplit(): void
    {
        $utterances = $this->segment([
            [0, 1500, ' Small.'], [1600, 3000, ' Small.'], [3100, 4600, ' Okay.'],
        ]);

        $this->assertCount(1, $utterances);
        $this->assertSame('Small. Small. Okay.', $utterances[0]->text);
    }

    /** A gap with no punctuation still splits — silence is the signal, not grammar. */
    public function testATimeGapWithoutPunctuationStillSplits(): void
    {
        $this->assertCount(2, $this->segment([[0, 1500, ' vegetable fried rice'], [5000, 6500, ' no bean sprouts']]));
    }

    /**
     * A breath mid-sentence must not become its own bubble.
     *
     * "with $2" / "each." is taken from the real sample: a gap long enough to split, a fragment short
     * enough to be meaningless, and a preceding run that does not end like a sentence.
     */
    public function testAShortFragmentIsKeptWithTheSentenceItBelongsTo(): void
    {
        $utterances = $this->segment([
            [0, 1500, ' A beef should be about with $2'],
            [2600, 3100, ' each.'],
        ]);

        $this->assertCount(1, $utterances);
        $this->assertSame('A beef should be about with $2 each.', $utterances[0]->text);
    }

    /** But a short reply after a finished sentence is a real turn and must survive. */
    public function testAShortUtteranceAfterASentenceEndingStandsAlone(): void
    {
        $utterances = $this->segment([
            [0, 1500, ' Would you like anything else?'],
            [2600, 3100, ' No.'],
        ]);

        $this->assertCount(2, $utterances);
        $this->assertSame('No.', $utterances[1]->text);
    }

    /**
     * The safety net. The sample held a 22.6-second run with no qualifying gap inside it — exactly the
     * giant bubble this feature exists to remove, and no silence threshold would have cut it.
     */
    public function testAnUtteranceIsCutAtTheMaximumDurationEvenWithoutSilence(): void
    {
        $tokens = [];
        for ($t = 0; $t < 40000; $t += 500) {
            $tokens[] = [$t, $t + 450, ' word'];
        }

        $utterances = $this->segment($tokens);

        $this->assertGreaterThan(1, count($utterances));
        foreach ($utterances as $utterance) {
            $this->assertLessThanOrEqual(self::MAX + 500, $utterance->endMs - $utterance->startMs);
        }
    }

    public function testAnEmptyTokenListProducesNothing(): void
    {
        $this->assertSame([], $this->segment([]));
    }

    public function testASingleTokenProducesOneUtterance(): void
    {
        $utterances = $this->segment([[0, 900, ' Hello.']]);

        $this->assertCount(1, $utterances);
        $this->assertSame('Hello.', $utterances[0]->text);
    }

    /**
     * Unusable timings produce one utterance, not invented boundaries.
     *
     * @dataProvider malformed
     *
     * @param list<array{0: int, 1: int, 2: string}> $tokens
     */
    public function testMalformedTimingsFallBackToOneUtterance(array $tokens, string $expected): void
    {
        $utterances = $this->segment($tokens);

        $this->assertCount(1, $utterances);
        $this->assertSame($expected, $utterances[0]->text);
    }

    /**
     * @return array<string, array{list<array{0: int, 1: int, 2: string}>, string}>
     */
    public static function malformed(): array
    {
        return [
            // Every timing identical: no gap can be measured anywhere.
            'all zero' => [[[0, 0, ' one'], [0, 0, ' two'], [0, 0, ' three']], 'one two three'],
            // The negative start is dropped; the rest is one run.
            'negative start' => [[[-500, 100, ' bad'], [0, 800, ' one'], [810, 1600, ' two']], 'one two'],
            // end before start is dropped.
            'inverted' => [[[0, 800, ' one'], [2000, 1000, ' bad'], [900, 1700, ' two']], 'one two'],
        ];
    }

    /** Overlapping tokens give a negative gap. That is not silence and must not split. */
    public function testOverlappingTimestampsDoNotSplit(): void
    {
        $this->assertCount(1, $this->segment([[0, 2000, ' one'], [1500, 3000, ' two']]));
    }

    /**
     * The leading-space contract is the only word-boundary signal, so joining is concatenation.
     *
     * whisper.cpp emits sub-word fragments; a space inserted between them would break the word.
     */
    public function testSubWordTokensJoinWithoutAnInventedSpace(): void
    {
        $utterances = $this->segment([[0, 400, ' S'], [400, 800, 'esame'], [800, 1200, ' chicken.']]);

        $this->assertSame('Sesame chicken.', $utterances[0]->text);
    }

    public function testEmptyTokensNeverCreateTurns(): void
    {
        $utterances = $this->segment([[0, 800, ' one'], [2000, 2100, '   '], [4000, 4800, ' two']]);

        foreach ($utterances as $utterance) {
            $this->assertNotSame('', $utterance->text);
        }
    }

    /**
     * The role is carried through, and nothing was clustered.
     *
     * @dataProvider roles
     */
    public function testTheGivenRoleIsCarriedOntoEveryUtterance(SpeakerRole $role): void
    {
        $utterances = $this->segment([[0, 1500, ' One.'], [5000, 6500, ' Two.']], $role);

        $this->assertCount(2, $utterances);
        foreach ($utterances as $utterance) {
            $this->assertSame($role, $utterance->role);
            $this->assertSame(SingleSpeakerUtteranceSegmenter::CHANNEL_SPEAKER, $utterance->speaker);
            // Given, not inferred: there is no probability to report.
            $this->assertSame(1.0, $utterance->confidence);
        }
    }

    /**
     * @return array<string, array{SpeakerRole}>
     */
    public static function roles(): array
    {
        return ['customer' => [SpeakerRole::CUSTOMER], 'agent' => [SpeakerRole::AGENT]];
    }

    /** The stored shape must be the one the pipeline already writes. */
    public function testTheStoredShapeMatchesTheExistingSegmentFormat(): void
    {
        $row = $this->segment([[0, 1500, ' Hello.']])[0]->toArray();

        $this->assertSame(['start_ms', 'end_ms', 'speaker', 'role', 'text', 'confidence'], array_keys($row));
        $this->assertSame(0, $row['start_ms']);
        $this->assertSame(1500, $row['end_ms']);
    }

    /**
     * @param list<array{0: int, 1: int, 2: string}> $tokens
     * @return list<\App\AudioToText\Domain\Speaker\SpeakerUtterance>
     */
    /**
     * Whisper's control markers are removed before anything measures a pause.
     *
     * Two separate failures, and the second is the one that went unnoticed. A marker left in the text
     * is stored, rendered and spoken aloud as though somebody had said `[_BEG_]`. And because a marker
     * sits **between** two sentences carrying timings that bridge the silence between them, leaving it
     * in hides the very gap this class exists to find — the first real Agent channel came back with
     * five sentences in one bubble.
     *
     * The markers here are placed exactly as whisper emits them: `[_BEG_]` opening a segment, and a
     * `[_TT_nnn]` marker spanning the pause between two utterances.
     */
    public function testWhisperControlMarkersAreRemovedAndDoNotHidePauses(): void
    {
        $utterances = $this->segment([
            [0, 400, '[_BEG_] The best Chinese cuisine,'],
            [400, 900, ' how can I help you?'],
            // The marker bridges the silence: it starts when the first utterance ends and finishes when
            // the second begins, so a segmenter that keeps it sees no gap at all.
            [900, 4000, '[_TT_350]'],
            [4000, 4600, ' For a pick up?'],
        ], SpeakerRole::AGENT);

        self::assertCount(2, $utterances, 'the bridged pause must still be found');

        foreach ($utterances as $utterance) {
            self::assertStringNotContainsString('[_', $utterance->text);
        }

        self::assertSame('The best Chinese cuisine, how can I help you?', $utterances[0]->text);
        self::assertSame('For a pick up?', $utterances[1]->text);
    }

    /** A marker is removed from a token that also holds words, leaving the words alone. */
    public function testAMarkerIsStrippedFromATokenThatAlsoHoldsWords(): void
    {
        $utterances = $this->segment([
            [0, 400, '[_BEG_] Hello'],
            [400, 800, ' there.'],
        ], SpeakerRole::CUSTOMER);

        self::assertCount(1, $utterances);
        self::assertSame('Hello there.', $utterances[0]->text);
    }

    private function segment(array $tokens, SpeakerRole $role = SpeakerRole::CUSTOMER): array
    {
        $segmenter = new SingleSpeakerUtteranceSegmenter(
            new UtteranceSettings(gapMs: self::GAP, maxDurationMs: self::MAX, minDurationMs: self::MIN),
        );

        return $segmenter->segment(
            array_map(static fn(array $t): TranscriptToken => new TranscriptToken($t[0], $t[1], $t[2]), $tokens),
            $role,
        );
    }
}
