<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Application\Settings\UtteranceSettings;
use App\AudioToText\Application\Speaker\SingleSpeakerUtteranceSegmenter;
use App\AudioToText\Domain\Speaker\CrossChannelEvidence;
use App\AudioToText\Domain\Speaker\SpeakerUtterance;
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
    /** Past this, a pause inside a sentence ends an utterance too. */
    private const SOFT_MAX = 9000;
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

    /**
     * A pause **inside a sentence** does not split a turn that is still short.
     *
     * The rule this replaced cut here, and on real channels that produced 22 of 29 turns starting
     * mid-phrase — ", like super combo for two?" — which reads as a transcription fault rather than as
     * the hesitation it was. These speakers pause inside a sentence about as often as they finish one,
     * and at the same lengths, so the pause alone cannot tell the two apart.
     */
    public function testAPauseInsideASentenceDoesNotSplitAShortTurn(): void
    {
        $utterances = $this->segment([
            [0, 1500, ' vegetable fried rice'],
            [5000, 6500, ' no bean sprouts'],
        ]);

        $this->assertCount(1, $utterances);
        $this->assertSame('vegetable fried rice no bean sprouts', $utterances[0]->text);
    }

    /**
     * Once the turn has run long, the same pause does end it.
     *
     * The fallback, and what keeps a speaker who never punctuates from waiting for a sentence that
     * never comes and being cut mid-word by the safety cap instead. The boundary is still real silence;
     * what changed is that the turn was already long enough to be worth breaking.
     */
    public function testAPauseInsideASentenceSplitsOnceTheTurnHasRunLong(): void
    {
        $utterances = $this->segment([
            // Past SOFT_MAX (9 s) by the time the pause arrives, and still under MAX (20 s).
            [0, 9500, ' vegetable fried rice with extra chilli and a side of'],
            [10600, 12000, ' no bean sprouts'],
        ]);

        $this->assertCount(2, $utterances);
        $this->assertSame('vegetable fried rice with extra chilli and a side of', $utterances[0]->text);
    }

    /**
     * A pause after a finished sentence splits immediately, however short the turn.
     *
     * The ordinary boundary, and the one that reads well. It is checked before the length fallback, so
     * a two-second exchange of complete sentences still arrives as two messages.
     */
    public function testAPauseAfterAFinishedSentenceSplitsStraightAway(): void
    {
        $utterances = $this->segment([
            [0, 1500, ' Vegetable fried rice.'],
            [2600, 4000, ' No bean sprouts.'],
        ]);

        $this->assertCount(2, $utterances);
        $this->assertSame('Vegetable fried rice.', $utterances[0]->text);
        $this->assertSame('No bean sprouts.', $utterances[1]->text);
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
     * A provider boundary ends a turn even though the tokens abut at 0 ms.
     *
     * **The headline case.** whisper.cpp tiles the timeline: every one of the 15 segment-to-segment
     * gaps on the measured 162-second call was exactly 0 ms. The gap rule can therefore never find
     * these boundaries, and before this the safety nets did the cutting — 4 of 11 turns came from the
     * hard cap and 8 of 11 began mid-phrase.
     */
    public function testAProviderBoundarySplitsEvenWithNoGapAtAll(): void
    {
        $utterances = $this->segment([
            [0, 4000, ' Hi, yes, I\'d like to place an order.', true],
            [4000, 8000, ' No, to delivery, you said 52 Hillside Avenue.'],
        ]);

        self::assertCount(2, $utterances);
        self::assertSame('Hi, yes, I\'d like to place an order.', $utterances[0]->text);
        self::assertSame('No, to delivery, you said 52 Hillside Avenue.', $utterances[1]->text);
    }

    /** And it splits below the gap threshold too — the boundary is structure, not silence. */
    public function testAProviderBoundaryOutranksTheGapThreshold(): void
    {
        // 100 ms apart: an order of magnitude under AUDIO_UTTERANCE_GAP_MS.
        $utterances = $this->segment([
            [0, 1500, ' Two orders of barbecue chicken.', true],
            [1600, 3000, ' With french fries.'],
        ]);

        self::assertCount(2, $utterances);
    }

    /**
     * A one-word provider utterance stands alone, however short.
     *
     * "Yes." runs 21400-22400 ms on the real call — a complete reply, and well under the 1200 ms
     * minimum. Folding it forward would merge an answer into the next question, and the engine had
     * already said they were two things.
     */
    public function testAShortProviderUtteranceIsNotFoldedIntoTheNext(): void
    {
        $utterances = $this->segment([
            [0, 2000, ' Two orders of barbecue chicken with french fries', true],
            [2100, 3100, ' Yes.', true],
            [3100, 7000, ' One with barbecue sauce all over everything.'],
        ]);

        self::assertCount(3, $utterances);
        self::assertSame('Yes.', $utterances[1]->text);
    }

    /**
     * The fold-back still protects a genuine fragment — one the provider did NOT end an utterance on.
     *
     * Without this the exemption would be a blanket "never fold", and the breath-mid-sentence case
     * this class was built for would come back.
     */
    public function testAFragmentWithNoProviderBoundaryIsStillFoldedBack(): void
    {
        $utterances = $this->segment([
            [0, 1500, ' A beef should be about with $2'],
            [2600, 3100, ' each.'],
        ]);

        self::assertCount(1, $utterances);
    }

    /** A provider segment that runs long is still cut by the safety net inside itself. */
    public function testALongProviderSegmentIsStillSplitInternally(): void
    {
        $utterances = $this->segment([
            [0, 10000, ' One with barbecue sauce all over everything and then some more words'],
            [11000, 19000, ' and the other one is barbecue sauce and ketchup on french fries', true],
        ]);

        // The soft max fires inside the provider segment; the boundary then closes the second part.
        self::assertCount(2, $utterances);
    }

    /** Text is conserved exactly: nothing lost, nothing duplicated, whatever the boundaries. */
    public function testProviderBoundariesConserveEveryWord(): void
    {
        $tokens = [
            [0, 2000, ' Hi, yes, I\'d like to place an order.', true],
            [2000, 4000, ' No, to delivery.', true],
            [4000, 5000, ' Yes.', true],
        ];

        $joined = '';
        foreach ($this->segment($tokens) as $utterance) {
            $joined .= ($joined === '' ? '' : ' ') . $utterance->text;
        }

        self::assertSame('Hi, yes, I\'d like to place an order. No, to delivery. Yes.', $joined);
    }

    /** Timestamps come through untouched — no boundary rule may invent or shift one. */
    public function testProviderBoundariesPreserveExactTimestamps(): void
    {
        $utterances = $this->segment([
            [1234, 5678, ' First utterance.', true],
            [5678, 9012, ' Second utterance.', true],
        ]);

        self::assertSame([1234, 5678], [$utterances[0]->startMs, $utterances[0]->endMs]);
        self::assertSame([5678, 9012], [$utterances[1]->startMs, $utterances[1]->endMs]);
    }

    /**
     * A control marker carrying the boundary does not take it down with it.
     *
     * whisper puts `[_TT_nnn]` at the end of a segment, so the token the boundary sits on is often
     * exactly the one that gets stripped. The flag has to survive the rebuild or the structure is lost
     * for precisely the segments that needed cleaning.
     */
    public function testABoundaryOnAStrippedMarkerIsNotLost(): void
    {
        $utterances = $this->segment([
            [0, 2000, ' Two orders of barbecue chicken.'],
            [2000, 2000, '[_TT_200]', true],
            [2050, 4000, ' With french fries.'],
        ]);

        self::assertCount(2, $utterances);
        self::assertSame('Two orders of barbecue chicken.', $utterances[0]->text);
        self::assertStringNotContainsString('[_', $utterances[1]->text);
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

    // ---------------------------------------------------------------------------------------------
    // Phase 2B — cross-channel refinement. The sibling confirms; our own punctuation positions the cut.
    // ---------------------------------------------------------------------------------------------

    /**
     * Rule B, on the real measured case from call 22613839.
     *
     * Whisper gave the Agent one segment for `"BBQ chicken wings? Anything else?"` — two questions asked
     * either side of the Customer answering. The gap rule cannot find the boundary because whisper
     * stretched `"?"` to 1700 ms instead of reporting the silence.
     */
    public function testTheGluedDoubleQuestionIsSeparatedWhenTheCustomerAnsweredBetweenThem(): void
    {
        $tokens = [
            [21310, 22660, ' BBQ'], [23100, 24930, ' chicken'], [28140, 28760, ' wings'],
            [29300, 31000, '?'], [31640, 32570, ' Anything'], [33660, 34990, ' else'], [35390, 36000, '?', true],
        ];

        // Phase 2A reproduces the defect exactly as it appears on the real call: the soft cap cuts after
        // "Anything", gluing the first question to the start of the second and orphaning its ending.
        $before = $this->segment($tokens, SpeakerRole::AGENT);
        $this->assertSame('BBQ chicken wings? Anything', $before[0]->text);
        $this->assertSame('else?', $before[1]->text);

        // The Customer answered twice, finishing at 29360 — between our "?" and our "Anything".
        $after = $this->segment($tokens, SpeakerRole::AGENT, $this->evidence([[21400, 22400], [22400, 29360]]));

        $this->assertCount(2, $after);
        $this->assertSame('BBQ chicken wings?', $after[0]->text);
        $this->assertSame('Anything else?', $after[1]->text);
    }

    /**
     * Rule A, on the real measured case from call 22414839: our sentence ends, they take the floor, we
     * carry on. Rule B cannot reach this one — the Agent's turn *ended* at 94000, by which point the
     * Customer had already resumed, and both positions it pointed at were inside a word.
     */
    public function testOurSentenceEndSplitsWhenTheOtherPartyThenTookTheFloor(): void
    {
        $tokens = [
            [86000, 86850, ' Okay'], [86850, 87190, ','], [87580, 87950, ' that'], [88120, 88510, "'s"],
            [88550, 89130, ' fine'], [89500, 90020, '.'],
            [90020, 90650, ' Can'], [90650, 90860, ' I'], [90860, 91180, ' order'], [91420, 92000, ' rangoon?', true],
        ];

        $this->assertCount(1, $this->segment($tokens), 'Phase 2A glues both sentences together.');

        // The Agent replied at 92070 — 2050 ms after our sentence ended at 90020.
        $after = $this->segment($tokens, SpeakerRole::CUSTOMER, $this->evidence([[92070, 94000], [95570, 99000]]));

        $this->assertCount(2, $after);
        $this->assertSame("Okay, that's fine.", $after[0]->text);
        $this->assertSame('Can I order rangoon?', $after[1]->text);
        $this->assertSame(90020, $after[1]->startMs, 'The cut lands on our own token boundary.');
    }

    /** The regression that made the first version of this unsafe: a price is not two sentences. */
    public function testAPriceIsNeverSplitAtItsDecimalPoint(): void
    {
        // Tokenised exactly as whisper produced it on call 22414839.
        $tokens = [
            [61970, 62190, ' be'], [62190, 62300, ' $'], [62300, 62960, '21'], [62960, 63290, '.'],
            [63290, 64000, '73'], [65320, 67080, '.', true],
        ];

        // The Customer's turn ended at 63000 — inside the price, which is what made this fire.
        $after = $this->segment($tokens, SpeakerRole::AGENT, $this->evidence([[53000, 63000], [68000, 70000]]));

        $this->assertCount(1, $after);
        $this->assertSame('be $21.73.', $after[0]->text);
    }

    /** The same guard, on a time rather than a price, and on a bare decimal. */
    public function testATimeAndABareDecimalAreNeverSplitEither(): void
    {
        foreach ([[' 7', '.', '30'], [' 0', '.', '75']] as [$head, $dot, $tail]) {
            $after = $this->segment(
                [[1000, 1500, ' at'], [1500, 2000, $head], [2000, 2500, $dot], [2500, 3000, $tail, true]],
                SpeakerRole::AGENT,
                $this->evidence([[500, 2200], [4000, 5000]]),
            );

            $this->assertCount(1, $after, 'A numeric continuation must never be cut.');
        }
    }

    /** A sentence end after a price still cuts: the digit guard needs a digit on *both* sides. */
    public function testASentenceEndingInAPriceStillSplitsWhenAWordFollows(): void
    {
        $tokens = [
            [1000, 1500, ' Total'], [1500, 2000, ' $'], [2000, 2400, '21'], [2400, 2600, '.'],
            [2600, 3000, '73'], [3000, 3200, '.'],
            [3300, 4000, ' Anything'], [4000, 4600, ' else?', true],
        ];

        $after = $this->segment($tokens, SpeakerRole::AGENT, $this->evidence([[500, 3250], [5000, 6000]]));

        $this->assertCount(2, $after);
        $this->assertSame('Total $21.73.', $after[0]->text);
        $this->assertSame('Anything else?', $after[1]->text);
    }

    /** No sibling, no change — byte for byte, which is what makes this refinement optional. */
    public function testWithoutEvidenceTheResultIsIdenticalToPhase2A(): void
    {
        $tokens = [
            [0, 900, ' Hi,'], [900, 1800, ' how'], [1800, 2600, ' are'], [2600, 3400, ' you?'],
            [3500, 4200, ' Fine'], [4200, 5000, ' thanks.', true],
        ];

        $this->assertSame(
            array_map(static fn(SpeakerUtterance $u): array => $u->toArray(), $this->segment($tokens)),
            array_map(static fn(SpeakerUtterance $u): array => $u->toArray(), $this->segment($tokens, SpeakerRole::CUSTOMER, null)),
        );
    }

    /**
     * The rule rejected in the measurements: the other party's turn end alone must never cut.
     *
     * Ungated, this took one call from 38 turns to 57 and mid-phrase openings from 24% to 47%. The
     * guard is that our own text must end like a sentence, so a handover mid-phrase changes nothing.
     */
    public function testAHandoverMidPhraseIsIgnored(): void
    {
        $tokens = [
            [0, 500, ' one'], [500, 1000, ' order'], [1000, 1500, ' of'], [1500, 2000, ' crab'],
            [2200, 2600, ' rang'], [2600, 3000, 'oon', true],
        ];

        // Their turn ends at 2100, squarely between two of our words — and mid-word, semantically.
        $this->assertCount(1, $this->segment($tokens, SpeakerRole::CUSTOMER, $this->evidence([[0, 2100], [4000, 5000]])));
    }

    /** Genuine simultaneous speech is not a handover: they started before we finished our sentence. */
    public function testOverlappingSpeechIsPreserved(): void
    {
        $tokens = [
            [10000, 10800, ' Yes'], [10800, 11600, ' that'], [11600, 12400, " 's"], [12400, 13000, ' right.'],
            [13100, 13900, ' And'], [13900, 14600, ' delivery.', true],
        ];

        // They were already talking from 5000 and stopped at 9000 — before our sentence ended at 13000,
        // and nothing starts within 3 s after it. Neither rule applies.
        $this->assertCount(1, $this->segment($tokens, SpeakerRole::CUSTOMER, $this->evidence([[5000, 9000], [20000, 21000]])));
    }

    /** Beyond the measured window a later turn is a new topic, not an answer to this sentence. */
    public function testTheOtherPartyTakingTheFloorLongAfterwardsDoesNotSplit(): void
    {
        $tokens = [
            [0, 900, ' Delivery'], [900, 1800, ' please.'], [2000, 2900, ' Thanks'], [2900, 3600, ' again.', true],
        ];

        // 5 s after our sentence ended at 1800 — outside FLOOR_WINDOW_MS.
        $this->assertCount(1, $this->segment($tokens, SpeakerRole::CUSTOMER, $this->evidence([[6800, 8000], [9000, 9500]])));
    }

    /** A cut may only ever fall where this channel already had a token boundary. */
    public function testEveryBoundaryLandsOnAnOwnTokenBoundary(): void
    {
        $tokens = [
            [21310, 22660, ' BBQ'], [23100, 24930, ' chicken'], [28140, 28760, ' wings'],
            [29300, 31000, '?'], [31640, 32570, ' Anything'], [33660, 34990, ' else'], [35390, 36000, '?', true],
        ];
        $starts = array_map(static fn(array $t): int => $t[0], $tokens);
        $ends = array_map(static fn(array $t): int => $t[1], $tokens);

        foreach ($this->segment($tokens, SpeakerRole::AGENT, $this->evidence([[21400, 22400], [22400, 29360]])) as $utterance) {
            $this->assertContains($utterance->startMs, $starts, 'A turn began inside a token.');
            $this->assertContains($utterance->endMs, $ends, 'A turn ended inside a token.');
        }
    }

    /** Refinement regroups words; it never adds, drops or repeats one. */
    public function testNoWordIsLostOrDuplicatedByCrossChannelRefinement(): void
    {
        $tokens = [
            [21310, 22660, ' BBQ'], [23100, 24930, ' chicken'], [28140, 28760, ' wings'],
            [29300, 31000, '?'], [31640, 32570, ' Anything'], [33660, 34990, ' else'], [35390, 36000, '?', true],
        ];
        $join = static fn(array $u): string => preg_replace(
            '/\s+/u',
            ' ',
            trim(implode(' ', array_map(static fn(SpeakerUtterance $x): string => $x->text, $u))),
        );

        $this->assertSame(
            $join($this->segment($tokens, SpeakerRole::AGENT)),
            $join($this->segment($tokens, SpeakerRole::AGENT, $this->evidence([[21400, 22400], [22400, 29360]]))),
        );
    }

    /** A short turn created by a cross-channel cut must not be folded straight back in. */
    public function testAShortRefinedTurnSurvivesTheMinimumDurationFold(): void
    {
        $tokens = [
            [0, 900, ' Delivery'], [900, 1800, ' please.'],
            [2000, 2400, ' Yes.'], [6000, 7500, ' Thanks.', true],
        ];

        $after = $this->segment($tokens, SpeakerRole::CUSTOMER, $this->evidence([[2500, 4000], [8000, 9000]]));

        $this->assertSame('Delivery please.', $after[0]->text);
        $this->assertSame('Yes.', $after[1]->text, 'A 400 ms turn below MIN stayed whole.');
    }

    /** A sibling with no usable clock is no evidence at all, and the result is Phase 2A. */
    public function testASiblingWithOneInstantForEveryTurnIsRejected(): void
    {
        $this->assertNull($this->evidence([[0, 5000], [0, 9000]]), 'All turns at one start is not a clock.');
        $this->assertNull($this->evidence([[4000, 5000], [1000, 2000]]), 'Turns running backwards are not a clock.');
        $this->assertNull($this->evidence([[5000, 1000], [6000, 9000]]), 'A turn ending before it starts is not a clock.');
        $this->assertNull($this->evidence([[0, 5000]]), 'One turn cannot say when a conversation changed hands.');
    }

    /** Deterministic roles are carried through untouched — refinement never reassigns a speaker. */
    public function testRefinementNeverChangesTheDeclaredRole(): void
    {
        $tokens = [
            [21310, 22660, ' BBQ'], [23100, 24930, ' chicken'], [28140, 28760, ' wings'],
            [29300, 31000, '?'], [31640, 32570, ' Anything'], [33660, 34990, ' else'], [35390, 36000, '?', true],
        ];
        $evidence = $this->evidence([[21400, 22400], [22400, 29360]]);

        foreach ($this->segment($tokens, SpeakerRole::AGENT, $evidence) as $utterance) {
            $this->assertSame(SpeakerRole::AGENT, $utterance->role);
            $this->assertSame(SingleSpeakerUtteranceSegmenter::CHANNEL_SPEAKER, $utterance->speaker);
        }

        foreach ($this->segment($tokens, SpeakerRole::CUSTOMER, $evidence) as $utterance) {
            $this->assertSame(SpeakerRole::CUSTOMER, $utterance->role);
        }
    }

    private function segment(
        array $tokens,
        SpeakerRole $role = SpeakerRole::CUSTOMER,
        ?CrossChannelEvidence $evidence = null,
    ): array {
        $segmenter = new SingleSpeakerUtteranceSegmenter(
            new UtteranceSettings(
                gapMs: self::GAP,
                maxDurationMs: self::MAX,
                softMaxDurationMs: self::SOFT_MAX,
                minDurationMs: self::MIN,
            ),
        );

        return $segmenter->segment(
            array_map(
                static fn(array $t): TranscriptToken => new TranscriptToken(
                    $t[0],
                    $t[1],
                    $t[2],
                    // Optional fourth element: whether whisper ended an utterance on this token.
                    (bool) ($t[3] ?? false),
                ),
                $tokens,
            ),
            $role,
            $evidence,
        );
    }

    /**
     * The opposite channel as the worker would have it: its stored turns, read back.
     *
     * Built through the real {@see CrossChannelEvidence::fromSiblingTurns()} from real
     * {@see SpeakerUtterance} values, so the timeline guard these tests rely on is the shipped one.
     *
     * @param list<array{int, int}> $spans the sibling's turn start/end pairs
     */
    private function evidence(array $spans): ?CrossChannelEvidence
    {
        return CrossChannelEvidence::fromSiblingTurns(array_map(
            static fn(array $s): SpeakerUtterance => new SpeakerUtterance(
                $s[0],
                $s[1],
                SingleSpeakerUtteranceSegmenter::CHANNEL_SPEAKER,
                SpeakerRole::AGENT,
                'sibling turn',
                1.0,
            ),
            $spans,
        ));
    }
}
