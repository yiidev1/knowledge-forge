<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Order58\RecordingDurationLimit;
use PHPUnit\Framework\TestCase;

use const INF;
use const NAN;

/**
 * The recording-duration rule: `total_amount × MULTIPLIER ÷ DIVISOR` minutes, compared in seconds, the
 * boundary kept, and every missing fact read as "do not hide".
 */
final class RecordingDurationLimitTest extends TestCase
{
    private function rule(float $multiplier = 2.0, float $divisor = 10.0): RecordingDurationLimit
    {
        return new RecordingDurationLimit(true, $multiplier, $divisor);
    }

    /** 84.48 × 2 ÷ 10 = 16.896 minutes = 16 min 53.76 s = 1013.76 s. */
    public function testTheFormulaIsDecimalMinutesTurnedIntoSeconds(): void
    {
        self::assertEqualsWithDelta(1013.76, $this->rule()->allowedSeconds('84.48'), 1e-9);
        self::assertEqualsWithDelta(1013.76, $this->rule()->allowedSeconds('84.48000'), 1e-9);
    }

    /** @return iterable<string, array{float, bool}> */
    public static function durations(): iterable
    {
        yield '12:30 shown' => [12 * 60 + 30, true];
        yield '16:00 shown' => [16 * 60, true];
        yield '16:53.76 exactly the limit, shown' => [16 * 60 + 53.76, true];
        yield '16:54 excluded' => [16 * 60 + 54, false];
        yield '18:00 excluded' => [18 * 60, false];
        yield '20:15 excluded' => [20 * 60 + 15, false];
    }

    /** @dataProvider durations */
    public function testARecordingIsKeptUpToAndIncludingTheLimit(float $seconds, bool $shown): void
    {
        self::assertSame($shown, $this->rule()->allowsRecording($seconds, '84.48'));
    }

    /** One hundredth of a second over is over: the threshold is never rounded first. */
    public function testTheComparisonIsExactToTheFraction(): void
    {
        self::assertTrue($this->rule()->allowsRecording(1013.76, '84.48'));
        self::assertFalse($this->rule()->allowsRecording(1013.77, '84.48'));
    }

    public function testAMissingFactNeverHidesAnything(): void
    {
        $rule = $this->rule();

        self::assertTrue($rule->allowsRecording(null, '84.48'), 'duration not measured yet');
        self::assertTrue($rule->allowsRecording(5000.0, null), 'total missing');
        self::assertTrue($rule->allowsRecording(5000.0, '0'), 'zero total');
        self::assertTrue($rule->allowsRecording(5000.0, '0.00000'), 'zero total, five decimals');
        self::assertTrue($rule->allowsRecording(5000.0, '-3.50'), 'negative total');
        self::assertTrue($rule->allowsRecording(5000.0, 'n/a'), 'not a number');
        self::assertNull($rule->allowedSeconds(null));
        self::assertNull($rule->allowedSeconds('0'));
    }

    /** Switched off: no limit exists, nothing is excluded, and no SQL is added anywhere. */
    public function testTheRuleSwitchedOffChangesNothing(): void
    {
        $off = new RecordingDurationLimit(false, 2.0, 10.0);

        self::assertFalse($off->isEnabled());
        self::assertNull($off->allowedSeconds('84.48'));
        self::assertTrue($off->allowsRecording(99999.0, '84.48'));
        self::assertTrue($off->keepsOrder([99999.0], '84.48'));
        self::assertNull($off->orderVisibleSql('o'));
        self::assertNull($off->recordingAllowedSql('r', 'o'));
        self::assertFalse((new RecordingDurationLimit())->isEnabled(), 'off by default');
    }

    /** The client's future formulas are two numbers, not code. */
    public function testChangingTheTwoNumbersChangesTheLimitEverywhere(): void
    {
        self::assertEqualsWithDelta(1520.64, $this->rule(3.0, 10.0)->allowedSeconds('84.48'), 1e-9);
        self::assertEqualsWithDelta(1267.2, $this->rule(2.0, 8.0)->allowedSeconds('84.48'), 1e-9);
        self::assertTrue($this->rule(3.0, 10.0)->allowsRecording(1500.0, '84.48'));
        self::assertFalse($this->rule(2.0, 10.0)->allowsRecording(1500.0, '84.48'));
        self::assertStringContainsString('* 8 >', (string) $this->rule(2.0, 8.0)->recordingAllowedSql('r', 'o'));
        self::assertStringContainsString('* 3 * 60', (string) $this->rule(3.0, 10.0)->recordingAllowedSql('r', 'o'));
    }

    /** A divisor of zero, a negative or a non-finite factor switches the rule off rather than misjudging. */
    public function testAnUnusableFormulaSwitchesTheRuleOff(): void
    {
        foreach ([[2.0, 0.0], [2.0, -10.0], [-2.0, 10.0], [INF, 10.0], [2.0, NAN], [0.0, 10.0]] as [$m, $d]) {
            self::assertFalse((new RecordingDurationLimit(true, $m, $d))->isEnabled(), $m . ' / ' . $d);
        }
    }

    /** An order is hidden only when it has recordings and every one of them is over its limit. */
    public function testAnOrderIsHiddenOnlyWhenEveryRecordingIsOver(): void
    {
        $rule = $this->rule();

        self::assertTrue($rule->keepsOrder([], '84.48'), 'no recordings');
        self::assertFalse($rule->keepsOrder([1100.0], '84.48'), 'its only recording is over');
        self::assertFalse($rule->keepsOrder([1100.0, 1200.0], '84.48'), 'both over');
        self::assertTrue($rule->keepsOrder([1100.0, 600.0], '84.48'), 'one within');
        self::assertTrue($rule->keepsOrder([1100.0, null], '84.48'), 'one not measured yet');
        self::assertTrue($rule->keepsOrder([1100.0], null), 'no total to judge by');
    }

    /** The SQL is built from validated numbers only: plain decimal literals, nothing else. */
    public function testTheSqlCarriesOnlyDecimalLiterals(): void
    {
        $sql = (string) $this->rule(0.125, 7.5)->orderVisibleSql('o');

        self::assertStringContainsString('kf_rd_r.duration_seconds * 7.5 > o.total_amount * 0.125 * 60', $sql);
        self::assertStringContainsString('o.total_amount > 0', $sql);
        self::assertStringContainsString('kf_rd_r.delisted_at IS NULL', $sql);
    }
}
