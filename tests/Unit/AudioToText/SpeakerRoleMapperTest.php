<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Application\Speaker\SpeakerRoleMapper;
use App\AudioToText\Domain\Speaker\SpeakerUtterance;
use App\AudioToText\Domain\SpeakerRole;
use PHPUnit\Framework\TestCase;

use function count;

/**
 * Role mapping, over already-separated neutral clusters.
 *
 * Every case here starts from clusters the diarizer has already distinguished by voice. That ordering
 * is the point of the whole design: this class decides which of two known-different speakers is the
 * agent, and is never asked to work out that there are two speakers in the first place.
 */
final class SpeakerRoleMapperTest extends TestCase
{
    private SpeakerRoleMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new SpeakerRoleMapper();
    }

    public function testATypicalOrderCallMapsBothRoles(): void
    {
        $result = $this->mapper->map($this->orderCall('SPEAKER_00', 'SPEAKER_01'));

        $this->assertNull($result['reason']);
        $this->assertSame(2, $result['speakers']);
        $this->assertGreaterThan(0.55, $result['confidence']);
        $this->assertSame(SpeakerRole::AGENT, $this->roleOf($result['utterances'], 'SPEAKER_00'));
        $this->assertSame(SpeakerRole::CUSTOMER, $this->roleOf($result['utterances'], 'SPEAKER_01'));
    }

    /**
     * The single most important negative case. Calls open with the customer speaking, with an automated
     * greeting, or mid-sentence — so being first must carry no weight at all. Swapping which cluster
     * says what has to swap the roles.
     */
    public function testTheFirstSpeakerIsNotAssumedToBeTheAgent(): void
    {
        // Identical conversation, cluster labels exchanged: the customer's lines now come from
        // SPEAKER_00, which speaks first.
        $result = $this->mapper->map($this->orderCall('SPEAKER_01', 'SPEAKER_00'));

        $this->assertNull($result['reason']);
        $this->assertSame(SpeakerRole::AGENT, $this->roleOf($result['utterances'], 'SPEAKER_01'));
        $this->assertSame(SpeakerRole::CUSTOMER, $this->roleOf($result['utterances'], 'SPEAKER_00'));
    }

    /**
     * One voice is not a conversation. It may be a monologue, a voicemail, or two similar-sounding
     * people the diarizer failed to separate — indistinguishable from here, so neither column is filled.
     */
    public function testASingleSpeakerNeedsReview(): void
    {
        $result = $this->mapper->map([
            $this->utterance(0, 2000, 'SPEAKER_00', 'Hello, would you like to place an order?'),
            $this->utterance(2000, 4000, 'SPEAKER_00', 'Pickup or delivery? What is the address?'),
        ]);

        $this->assertSame('only one speaker was detected', $result['reason']);
        $this->assertSame(0.0, $result['confidence']);
        $this->assertSame(SpeakerRole::UNKNOWN, $result['utterances'][0]->role);
    }

    public function testNoSpeakersNeedsReview(): void
    {
        $result = $this->mapper->map([]);

        $this->assertSame('no speaker clusters were produced', $result['reason']);
        $this->assertSame(0, $result['speakers']);
    }

    /**
     * A third voice — background television, a colleague, a bystander — must not crash the mapping and
     * must not be folded into either role column.
     */
    public function testAThirdSpeakerDoesNotCrashAndIsNotGivenARole(): void
    {
        $utterances = $this->orderCall('SPEAKER_00', 'SPEAKER_01');
        $utterances[] = $this->utterance(9000, 9500, 'SPEAKER_02', 'mm');

        $result = $this->mapper->map($utterances);

        $this->assertSame(3, $result['speakers']);
        $this->assertSame(SpeakerRole::OTHER, $this->roleOf($result['utterances'], 'SPEAKER_02'));
        // The two participants who actually carried the conversation still map.
        $this->assertSame(SpeakerRole::AGENT, $this->roleOf($result['utterances'], 'SPEAKER_00'));
        $this->assertSame(SpeakerRole::CUSTOMER, $this->roleOf($result['utterances'], 'SPEAKER_01'));
    }

    /**
     * Two people who both sound like the agent produce a near-zero margin. A coin-flip presented as a
     * fact is worse than an honest "needs review", so the caller's threshold rejects it.
     */
    public function testAnAmbiguousConversationProducesLowConfidence(): void
    {
        $result = $this->mapper->map([
            $this->utterance(0, 1000, 'SPEAKER_00', 'Would you like to place an order? Pickup or delivery?'),
            $this->utterance(1000, 2000, 'SPEAKER_01', 'Would you like to place an order? Pickup or delivery?'),
        ]);

        $this->assertLessThan(0.55, $result['confidence']);
    }

    public function testAConversationWithNoRoleSignalsNeedsReview(): void
    {
        $result = $this->mapper->map([
            $this->utterance(0, 1000, 'SPEAKER_00', 'mmm'),
            $this->utterance(1000, 2000, 'SPEAKER_01', 'ahh'),
        ]);

        $this->assertSame('no role signals were found in either speaker', $result['reason']);
        $this->assertSame(SpeakerRole::UNKNOWN, $result['utterances'][0]->role);
    }

    /** Unattributed speech stays unattributed through role mapping. */
    public function testUnattributedSpeechNeverReceivesARole(): void
    {
        $utterances = $this->orderCall('SPEAKER_00', 'SPEAKER_01');
        $utterances[] = $this->utterance(9000, 9400, SpeakerRole::UNKNOWN->value, 'inaudible');

        $result = $this->mapper->map($utterances);

        $this->assertSame(SpeakerRole::UNKNOWN, $this->roleOf($result['utterances'], SpeakerRole::UNKNOWN->value));
    }

    /**
     * The mapper labels utterances; it never re-attributes them.
     *
     * Worth pinning explicitly, because when a split comes out lopsided this is the first thing to
     * suspect — and it should stay provably not the cause. Which neutral cluster said what is settled
     * by diarization and alignment; this class only decides what to call the clusters, so text, timing
     * and speaker must all come back untouched.
     */
    public function testMappingNeverMovesAnUtteranceBetweenNeutralSpeakers(): void
    {
        $before = $this->orderCall('SPEAKER_00', 'SPEAKER_01');
        $after = $this->mapper->map($before)['utterances'];

        $this->assertCount(count($before), $after);

        foreach ($before as $index => $original) {
            $this->assertSame($original->speaker, $after[$index]->speaker);
            $this->assertSame($original->text, $after[$index]->text);
            $this->assertSame($original->startMs, $after[$index]->startMs);
            $this->assertSame($original->endMs, $after[$index]->endMs);
        }
    }

    /** The same holds on the path where no mapping could be made at all. */
    public function testAnUnresolvedMappingAlsoLeavesNeutralSpeakersIntact(): void
    {
        $before = [
            $this->utterance(0, 1000, 'SPEAKER_00', 'mmm'),
            $this->utterance(1000, 2000, 'SPEAKER_01', 'ahh'),
        ];
        $after = $this->mapper->map($before)['utterances'];

        foreach ($before as $index => $original) {
            $this->assertSame($original->speaker, $after[$index]->speaker);
            $this->assertSame($original->text, $after[$index]->text);
        }
    }

    // ---------------------------------------------------------------- dialogue-structure evidence

    /**
     * The plain case, and the one the old keyword scoring could barely tell apart: five consecutive
     * exchanges all orienting the same way should be decided, not hedged.
     */
    public function testAClearOrderCallIsConfidentlyMapped(): void
    {
        $result = $this->mapper->map($this->structuredCall('SPEAKER_00', 'SPEAKER_01'));

        $this->assertGreaterThanOrEqual(0.55, $result['confidence']);
        $this->assertSame(SpeakerRole::AGENT, $this->roleOf($result['utterances'], 'SPEAKER_00'));
        $this->assertSame(SpeakerRole::CUSTOMER, $this->roleOf($result['utterances'], 'SPEAKER_01'));
    }

    /**
     * sherpa assigns cluster numbers by order of appearance, so the same call re-run can label the two
     * voices the other way round. Nothing about the answer may depend on that.
     */
    public function testSwappingTheClusterLabelsSwapsTheRolesAndNothingElse(): void
    {
        $straight = $this->mapper->map($this->structuredCall('SPEAKER_00', 'SPEAKER_01'));
        $swapped = $this->mapper->map($this->structuredCall('SPEAKER_01', 'SPEAKER_00'));

        $this->assertSame(SpeakerRole::AGENT, $this->roleOf($swapped['utterances'], 'SPEAKER_01'));
        $this->assertSame(SpeakerRole::CUSTOMER, $this->roleOf($swapped['utterances'], 'SPEAKER_00'));
        // Same evidence, so the same certainty: the labels are not an input to the decision.
        $this->assertSame($straight['confidence'], $swapped['confidence']);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function adjacencyPairs(): iterable
    {
        yield 'address' => ["Okay, what's the address?", '140 Main Street, apartment 2.'];
        yield 'payment' => ['Cash or card?', 'Cash.'];
        yield 'delivery method' => ['Pickup or delivery?', 'Delivery.'];
        yield 'order' => ['What would you like to order?', 'Sesame chicken and two Cokes.'];
        yield 'anything else' => ['Anything else?', "No, that's it."];
    }

    /**
     * Each pair on its own orients the call correctly. Confidence is not asserted here — one exchange is
     * deliberately not enough evidence to publish on, which the volume factor enforces.
     *
     * @dataProvider adjacencyPairs
     */
    public function testASingleAdjacencyPairOrientsTheCall(string $question, string $answer): void
    {
        $result = $this->mapper->map([
            $this->utterance(0, 2000, 'SPEAKER_01', $question),
            $this->utterance(2000, 4000, 'SPEAKER_00', $answer),
        ]);

        $this->assertSame(SpeakerRole::AGENT, $this->roleOf($result['utterances'], 'SPEAKER_01'));
        $this->assertSame(SpeakerRole::CUSTOMER, $this->roleOf($result['utterances'], 'SPEAKER_00'));
    }

    /** Quoting the total and the delivery window is something only the order-taker does. */
    public function testQuotingPriceAndDeliveryTimeIdentifiesTheAgent(): void
    {
        $result = $this->mapper->map([
            $this->utterance(0, 3000, 'SPEAKER_00', 'So your total is $27.75 and delivery is in 30 minutes.'),
            $this->utterance(3000, 4000, 'SPEAKER_01', 'Okay, thank you very much indeed.'),
        ]);

        $this->assertSame(SpeakerRole::AGENT, $this->roleOf($result['utterances'], 'SPEAKER_00'));
    }

    /**
     * The failure mode from the real recording: a short fragment of the agent's speech lands on the
     * customer. It must dent confidence without flipping the answer.
     */
    public function testAMisattributedFragmentDoesNotFlipTheMapping(): void
    {
        $utterances = $this->structuredCall('SPEAKER_00', 'SPEAKER_01');
        // "let me recap" is an agent move; here the diarizer has pinned it on the customer.
        $utterances[] = $this->utterance(20000, 20800, 'SPEAKER_01', 'okay let me recap');

        $result = $this->mapper->map($utterances);

        $this->assertSame(SpeakerRole::AGENT, $this->roleOf($result['utterances'], 'SPEAKER_00'));
        $this->assertGreaterThanOrEqual(0.55, $result['confidence']);
    }

    /**
     * The echo. An agent repeating the customer's address and order back is doing the job correctly, and
     * used to be scored as evidence that they were the customer.
     */
    public function testAnAgentEchoingTheOrderDoesNotBecomeTheCustomer(): void
    {
        $utterances = $this->structuredCall('SPEAKER_00', 'SPEAKER_01');
        $utterances[] = $this->utterance(21000, 23000, 'SPEAKER_00', '140 Main Street, apartment 2, correct?');
        $utterances[] = $this->utterance(23000, 25000, 'SPEAKER_00', 'Two orders of chicken wings and a Coca-Cola.');

        $result = $this->mapper->map($utterances);

        $this->assertSame(SpeakerRole::AGENT, $this->roleOf($result['utterances'], 'SPEAKER_00'));
        $this->assertGreaterThanOrEqual(0.55, $result['confidence']);
    }

    /** An isolated word carries almost nothing; several completed exchanges carry the decision. */
    public function testAnIsolatedKeywordDoesNotOutweighStructure(): void
    {
        $utterances = $this->structuredCall('SPEAKER_00', 'SPEAKER_01');
        $utterances[] = $this->utterance(26000, 26400, 'SPEAKER_01', 'cash');

        $result = $this->mapper->map($utterances);

        $this->assertSame(SpeakerRole::AGENT, $this->roleOf($result['utterances'], 'SPEAKER_00'));
        $this->assertGreaterThanOrEqual(0.55, $result['confidence']);
    }

    /** Four words of pleasantries reveal nothing, and must not be dressed up as a finding. */
    public function testAVeryShortAmbiguousCallStaysUnpublishable(): void
    {
        $result = $this->mapper->map([
            $this->utterance(0, 500, 'SPEAKER_00', 'Hello'),
            $this->utterance(500, 1000, 'SPEAKER_01', 'Hi'),
            $this->utterance(1000, 1500, 'SPEAKER_00', 'Yes'),
            $this->utterance(1500, 2000, 'SPEAKER_01', 'Okay'),
        ]);

        $this->assertLessThan(0.55, $result['confidence']);
    }

    /** Recording started late: no greeting at all, and the structure still has to carry it. */
    public function testAMidCallRecordingWithNoGreetingStillMaps(): void
    {
        $result = $this->mapper->map([
            $this->utterance(0, 2000, 'SPEAKER_01', 'and what is the address there?'),
            $this->utterance(2000, 4000, 'SPEAKER_00', '88 Chestnut Road, apartment 4C.'),
            $this->utterance(4000, 5600, 'SPEAKER_01', 'Anything else with that?'),
            $this->utterance(5600, 6600, 'SPEAKER_00', "No, that's all."),
            $this->utterance(6600, 8200, 'SPEAKER_01', 'Cash or card?'),
            $this->utterance(8200, 8700, 'SPEAKER_00', 'Card.'),
        ]);

        $this->assertGreaterThanOrEqual(0.55, $result['confidence']);
        $this->assertSame(SpeakerRole::AGENT, $this->roleOf($result['utterances'], 'SPEAKER_01'));
        $this->assertSame(SpeakerRole::CUSTOMER, $this->roleOf($result['utterances'], 'SPEAKER_00'));
    }

    /**
     * The conversation from the task description, verbatim in structure: five exchanges, each a question
     * from one side answered by the other.
     *
     * @return list<SpeakerUtterance>
     */
    private function structuredCall(string $agent, string $customer): array
    {
        return [
            $this->utterance(0, 2000, $agent, 'What would you like to order?'),
            $this->utterance(2000, 4000, $customer, 'Sesame chicken.'),
            $this->utterance(4000, 6000, $agent, 'Pickup or delivery?'),
            $this->utterance(6000, 7000, $customer, 'Delivery.'),
            $this->utterance(7000, 9000, $agent, "What's the address?"),
            $this->utterance(9000, 11000, $customer, '140 Main Street.'),
            $this->utterance(11000, 13000, $agent, 'Cash or card?'),
            $this->utterance(13000, 14000, $customer, 'Cash.'),
            $this->utterance(14000, 16000, $agent, 'Delivery in 30 minutes.'),
            $this->utterance(16000, 17000, $customer, 'Thank you.'),
        ];
    }

    /**
     * The conversation from the reference recording, condensed. `$agent` and `$customer` name which
     * neutral cluster speaks which side, so a test can swap them without changing the words.
     *
     * @return list<SpeakerUtterance>
     */
    private function orderCall(string $agent, string $customer): array
    {
        return [
            $this->utterance(0, 900, $agent, 'Hello?'),
            $this->utterance(900, 1600, $customer, 'Hello.'),
            $this->utterance(1600, 3000, $agent, 'Yes, do you want to place an order?'),
            $this->utterance(3000, 3400, $customer, 'Yes.'),
            $this->utterance(3400, 4600, $agent, 'For pickup or delivery?'),
            $this->utterance(4600, 5200, $customer, 'Delivery please.'),
            $this->utterance(5200, 6400, $agent, "Okay, what's the address?"),
            $this->utterance(6400, 7800, $customer, 'Tori Guales 3, apartment 1B.'),
            $this->utterance(7800, 8600, $agent, 'What would you like to order?'),
            $this->utterance(8600, 10200, $customer, 'Two orders of chicken wings with tostones.'),
            $this->utterance(10200, 11000, $agent, 'Anything else?'),
            $this->utterance(11000, 12200, $customer, 'Two cans of Coke. That is it.'),
            $this->utterance(12200, 13400, $agent, 'Cash or card?'),
            $this->utterance(13400, 13900, $customer, 'Cash.'),
            $this->utterance(13900, 15400, $agent, 'Delivery time is 30 minutes. Thank you.'),
            $this->utterance(15400, 16000, $customer, 'Thank you. Bye.'),
        ];
    }

    private function utterance(int $start, int $end, string $speaker, string $text): SpeakerUtterance
    {
        return new SpeakerUtterance($start, $end, $speaker, SpeakerRole::UNKNOWN, $text, 1.0);
    }

    /**
     * @param list<SpeakerUtterance> $utterances
     */
    private function roleOf(array $utterances, string $speaker): SpeakerRole
    {
        foreach ($utterances as $utterance) {
            if ($utterance->speaker === $speaker) {
                return $utterance->role;
            }
        }

        self::fail('No utterance found for ' . $speaker);
    }
}
