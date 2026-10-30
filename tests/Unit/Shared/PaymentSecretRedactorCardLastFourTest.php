<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Order58\PaymentSecretRedactor;
use PHPUnit\Framework\TestCase;

use function json_encode;

/**
 * The one fact about a card an order may keep: its last four digits, and only when they can be proved.
 *
 * Every value here is synthetic. The token is a made-up string in the shape the provider uses — measured
 * on a real Case Order response without its values ever being printed — and never a card number.
 */
final class PaymentSecretRedactorCardLastFourTest extends TestCase
{
    private const TOKEN = 'U1lOVEhFVElDLVRFU1QtQ0FSRC1UT0tFTg==';

    public function testTheMeasuredShapeYieldsItsFourDigits(): void
    {
        self::assertSame('4242', PaymentSecretRedactor::cardLastFour($this->order(self::TOKEN . '|4242')));
    }

    public function testEitherCopyAloneIsEnough(): void
    {
        self::assertSame('0007', PaymentSecretRedactor::cardLastFour(['data' => ['card_num' => self::TOKEN . '|0007']]));
        self::assertSame('0007', PaymentSecretRedactor::cardLastFour(['data' => ['cc' => ['card_num' => self::TOKEN . '|0007']]]));
    }

    public function testAJsonEncodedDataStringIsReadToo(): void
    {
        $raw = ['data' => json_encode(['card_num' => self::TOKEN . '|1881', 'cc' => ['card_num' => self::TOKEN . '|1881']])];

        self::assertSame('1881', PaymentSecretRedactor::cardLastFour($raw));
    }

    /** Two copies that disagree prove nothing, so nothing is kept. */
    public function testCopiesThatDisagreeYieldNothing(): void
    {
        $raw = ['data' => ['card_num' => self::TOKEN . '|4242', 'cc' => ['card_num' => self::TOKEN . '|1111']]];

        self::assertNull(PaymentSecretRedactor::cardLastFour($raw));
    }

    /** One copy in an unknown shape is enough to distrust the other. */
    public function testOneMalformedCopySpoilsBoth(): void
    {
        $raw = ['data' => ['card_num' => self::TOKEN . '|4242', 'cc' => ['card_num' => 'something else']]];

        self::assertNull(PaymentSecretRedactor::cardLastFour($raw));
    }

    /** @dataProvider unsupportedReferences */
    public function testAnyOtherShapeYieldsNothing(string $reference): void
    {
        self::assertNull(PaymentSecretRedactor::cardLastFour($this->order($reference)));
    }

    /** @return iterable<string, array{string}> */
    public static function unsupportedReferences(): iterable
    {
        // A card number in clear is a different document from the one the rule was measured on.
        yield 'clear number' => ['4242424242424242'];
        yield 'clear number with a suffix' => ['4242424242424242|4242'];
        yield 'five digits' => [self::TOKEN . '|42424'];
        yield 'three digits' => [self::TOKEN . '|424'];
        yield 'not digits' => [self::TOKEN . '|42a2'];
        yield 'token too short' => ['abc|4242'];
        yield 'two separators' => [self::TOKEN . '|9999|4242'];
        yield 'whitespace in the token' => ['U1lOVEhF VElD|4242'];
        yield 'trailing space' => [self::TOKEN . '|4242 '];
        yield 'separator only' => ['|4242'];
    }

    public function testAnOrderWithoutACardYieldsNothing(): void
    {
        self::assertNull(PaymentSecretRedactor::cardLastFour(['payment_method' => 'cash', 'data' => ['cc' => []]]));
        self::assertNull(PaymentSecretRedactor::cardLastFour([]));
    }

    /** Redaction keeps the four digits and removes every secret exactly as before. */
    public function testRedactionKeepsTheDigitsAndNothingElse(): void
    {
        $raw = $this->order(self::TOKEN . '|4242');
        $raw['data']['cvv'] = '000';
        $raw['data']['exp_date'] = '12/2099';
        $raw['data']['billing_zip'] = '00000';
        $raw['data']['street1'] = '1 Synthetic Billing Way';
        $raw['data']['cc'] += ['cvv' => '000', 'exp_date' => '12/2099'];

        $clean = PaymentSecretRedactor::redactKeepingCardLastFour($raw);
        $json = (string) json_encode($clean);

        self::assertSame('4242', $clean[PaymentSecretRedactor::CARD_LAST_FOUR]);

        foreach (PaymentSecretRedactor::PAYMENT_SECRETS as $secret) {
            self::assertStringNotContainsString('"' . $secret . '"', $json, $secret . ' must not survive');
        }

        foreach ([self::TOKEN, '12/2099', '"000"', '00000', 'Synthetic Billing'] as $value) {
            self::assertStringNotContainsString($value, $json);
        }
    }

    /** What arrives under the kept name is not trusted: it is replaced by what was proved, or removed. */
    public function testAnArrivingValueUnderTheKeptNameIsNotTrusted(): void
    {
        $proved = PaymentSecretRedactor::redactKeepingCardLastFour(
            $this->order(self::TOKEN . '|1234') + [PaymentSecretRedactor::CARD_LAST_FOUR => '9999'],
        );
        self::assertSame('1234', $proved[PaymentSecretRedactor::CARD_LAST_FOUR]);

        $unproved = PaymentSecretRedactor::redactKeepingCardLastFour(
            ['data' => ['card_num' => 'garbage']] + [PaymentSecretRedactor::CARD_LAST_FOUR => '9999'],
        );
        self::assertArrayNotHasKey(PaymentSecretRedactor::CARD_LAST_FOUR, $unproved);
    }

    /** An order already redacted is asked for the digits a previous call kept — exactly four, or none. */
    public function testKeptDigitsAreReadBackOnlyWhenTheyAreFour(): void
    {
        self::assertSame('5678', PaymentSecretRedactor::cardLastFour([PaymentSecretRedactor::CARD_LAST_FOUR => '5678']));
        self::assertNull(PaymentSecretRedactor::cardLastFour([PaymentSecretRedactor::CARD_LAST_FOUR => '56789']));
        self::assertNull(PaymentSecretRedactor::cardLastFour([PaymentSecretRedactor::CARD_LAST_FOUR => 5678]));
    }

    /** Line items carry no card reference and are not given the key. */
    public function testPlainRedactionAddsNothing(): void
    {
        self::assertArrayNotHasKey(
            PaymentSecretRedactor::CARD_LAST_FOUR,
            PaymentSecretRedactor::redact($this->order(self::TOKEN . '|4242')),
        );
    }

    /** @return array<string, mixed> */
    private function order(string $reference): array
    {
        return ['id' => 1, 'payment_method' => 'credit card', 'data' => [
            'card_num' => $reference,
            'cc' => ['card_num' => $reference],
        ]];
    }
}
