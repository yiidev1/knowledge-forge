<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Application\Speaker\DialogueActDetector;
use App\AudioToText\Domain\Speaker\DialogueAct;
use PHPUnit\Framework\TestCase;

/**
 * What each utterance is *doing*, independently of who said it.
 *
 * The family-suppression cases are the important ones. "Cash or card?" contains the word "cash", and
 * counting that as the speaker choosing cash is a large part of why the old scoring credited the agent's
 * own questions to the customer.
 */
final class DialogueActDetectorTest extends TestCase
{
    private DialogueActDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new DialogueActDetector();
    }

    /**
     * @return iterable<string, array{0: string, 1: DialogueAct}>
     */
    public static function questionsSuppressTheirOwnAnswer(): iterable
    {
        yield 'payment' => ['Cash or card?', DialogueAct::ASK_PAYMENT];
        yield 'delivery method' => ['Is that for pickup or delivery?', DialogueAct::ASK_DELIVERY_METHOD];
        yield 'address' => ["What's the address please?", DialogueAct::ASK_ADDRESS];
        yield 'quantity' => ['How many orders?', DialogueAct::ASK_QUANTITY];
        yield 'anything else' => ['Anything else?', DialogueAct::ASK_ANYTHING_ELSE];
    }

    /**
     * @dataProvider questionsSuppressTheirOwnAnswer
     */
    public function testAQuestionIsNeverAlsoItsOwnAnswer(string $text, DialogueAct $expected): void
    {
        $acts = $this->detector->detect($text);

        self::assertContains($expected, $acts);

        $answer = $expected->answeredBy();
        self::assertNotNull($answer);
        self::assertNotContains($answer, $acts, 'The question was also read as the answer to itself.');
    }

    public function testABareChoiceIsTheAnswerNotTheQuestion(): void
    {
        self::assertContains(DialogueAct::SELECT_PAYMENT, $this->detector->detect('Cash.'));
        self::assertNotContains(DialogueAct::ASK_PAYMENT, $this->detector->detect('Cash.'));
    }

    /** "delivery in 45 minutes" is the agent quoting a window, not the customer choosing delivery. */
    public function testAQuotedDeliveryTimeIsNotAChoiceOfDeliveryMethod(): void
    {
        $acts = $this->detector->detect('Delivery in 45 minutes, thank you.');

        self::assertContains(DialogueAct::QUOTE_DELIVERY_TIME, $acts);
        self::assertNotContains(DialogueAct::SELECT_DELIVERY_METHOD, $acts);
    }

    /** A bare "minutes" used to match the old signal list. It carries no role information. */
    public function testMinutesWithoutANumberIsNotADeliveryQuote(): void
    {
        self::assertNotContains(
            DialogueAct::QUOTE_DELIVERY_TIME,
            $this->detector->detect('Give me a couple of minutes to think.'),
        );
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function prices(): iterable
    {
        yield 'dollar sign' => ['That comes to $27.75.'];
        yield 'bare decimal' => ['It is 27.75 altogether.'];
        yield 'spoken dollars' => ['That will be 28 dollars.'];
    }

    /**
     * @dataProvider prices
     */
    public function testAPriceIsRecognised(string $text): void
    {
        self::assertContains(DialogueAct::QUOTE_PRICE, $this->detector->detect($text));
    }

    public function testAnAddressNeedsBothANumberAndAThoroughfare(): void
    {
        self::assertContains(DialogueAct::PROVIDE_ADDRESS, $this->detector->detect('140 Main Street.'));
        // "street" alone is how an agent's unrelated chatter used to score as a customer giving an address.
        self::assertNotContains(DialogueAct::PROVIDE_ADDRESS, $this->detector->detect('The street was busy.'));
    }

    /** The caller announcing their own intent is not the order-taker inviting one. */
    public function testTheCallerAskingToOrderIsDistinguishedFromTheAgentOffering(): void
    {
        self::assertContains(DialogueAct::REQUEST_ORDER, $this->detector->detect("I'd like to place an order."));
        self::assertContains(DialogueAct::ASK_ORDER, $this->detector->detect('You want to place an order?'));
    }

    public function testSilenceAndNoiseProduceNothing(): void
    {
        self::assertSame([], $this->detector->detect(''));
        self::assertSame([], $this->detector->detect('   '));
        self::assertSame([], $this->detector->detect('mm hmm'));
    }

    /** Detection is on lower-cased, whitespace-normalised text, so transcription casing cannot matter. */
    public function testDetectionIsInsensitiveToCaseAndSpacing(): void
    {
        self::assertContains(DialogueAct::ASK_PAYMENT, $this->detector->detect("CASH   OR\n CARD?"));
    }
}
