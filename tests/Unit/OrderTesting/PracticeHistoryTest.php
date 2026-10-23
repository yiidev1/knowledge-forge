<?php

declare(strict_types=1);

namespace App\Tests\Unit\OrderTesting;

use App\OrderTesting\Domain\AttemptStatus;
use App\OrderTesting\Domain\Comparison\ComparisonScore;
use App\OrderTesting\Domain\Comparison\SectionScore;
use App\OrderTesting\Domain\InitiatorType;
use App\OrderTesting\Domain\Practice\AttemptGroup;
use App\OrderTesting\Domain\Practice\DemoOutcome;
use App\OrderTesting\Domain\Practice\PracticeTry;
use App\OrderTesting\Domain\TestAttempt;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The shapes the Practice Attempts page is drawn from.
 *
 * Pure value objects, so this needs no database and no fixtures. What is guarded here is the
 * arithmetic a reader will believe without checking: which try is "best", what counts as perfect,
 * and what an unowned demo order is called.
 */
final class PracticeHistoryTest extends Unit
{
    /**
     * The one that matters most on this page: a near-miss is never treated as a perfect match.
     *
     * Two independent guards agree here, and the test asserts both because either alone would be
     * enough to mislead a trainee if the other were changed:
     *
     * - {@see SectionScore::display()} refuses to round UP to 100 — 99.6 prints as 99, not 100 — so
     *   the number on the card cannot claim perfection the comparison does not support;
     * - {@see DemoOutcome::isPerfect()} reads the RAW score, so the "Completed" badge and the
     *   full-detail treatment cannot be reached by a rounding rule at all.
     */
    public function testANearMissIsNeitherPrintedNorTreatedAsPerfect(): void
    {
        $almost = new DemoOutcome($this->demo(), new ComparisonScore(99.6, null, []));

        self::assertSame(99, $almost->score->displayPercent(), 'rounding must not invent a 100');
        self::assertFalse($almost->isPerfect(), 'and it is not a perfect match');
    }

    public function testAnExactHundredIsPerfect(): void
    {
        self::assertTrue((new DemoOutcome($this->demo(), new ComparisonScore(100.0, null, [])))->isPerfect());
    }

    /** Floating-point error in a weighted sum must not cost somebody their perfect score. */
    public function testAHundredArrivedAtByArithmeticIsStillPerfect(): void
    {
        $sum = 0.0;

        for ($i = 0; $i < 10; $i++) {
            $sum += 10.0;
        }

        self::assertTrue((new DemoOutcome($this->demo(), new ComparisonScore($sum, null, [])))->isPerfect());
    }

    public function testAnUnavailableScoreIsNotPerfect(): void
    {
        $none = new DemoOutcome($this->demo(), new ComparisonScore(null, 'no original order', []));

        self::assertFalse($none->isPerfect());
        self::assertNull($none->score->displayPercent());
    }

    /**
     * One attempt can produce several demo orders, and the card scores the best of them.
     *
     * The others are kept — the page says how many there were — because "one click produced three
     * orders" is a thing that happens and discarding two of them would lose real history.
     */
    public function testATryWithSeveralDemoOrdersIsJudgedOnItsBest(): void
    {
        $try = new PracticeTry(1, $this->attempt(1, InitiatorType::Agent, 7), [
            new DemoOutcome($this->demo(1), new ComparisonScore(40.0, null, [])),
            new DemoOutcome($this->demo(2), new ComparisonScore(91.0, null, [])),
            new DemoOutcome($this->demo(3), new ComparisonScore(72.0, null, [])),
        ]);

        self::assertSame(3, $try->demoCount());
        self::assertSame(91.0, $try->best()?->score->overall);
    }

    /** A try whose demo orders all failed to score still shows one, so the card can say why. */
    public function testATryWhoseOutcomesCannotBeScoredStillOffersOne(): void
    {
        $try = new PracticeTry(1, $this->attempt(1, InitiatorType::Agent, 7), [
            new DemoOutcome($this->demo(1), new ComparisonScore(null, 'no original order', [])),
        ]);

        self::assertNotNull($try->best());
        self::assertNull($try->best()?->score->overall);
    }

    public function testAnAttemptWithNothingImportedIsPending(): void
    {
        $try = new PracticeTry(1, $this->attempt(1, InitiatorType::Agent, 7), []);

        self::assertTrue($try->isPending());
        self::assertFalse($try->isUnmatched());
        self::assertNull($try->best());
    }

    /** A demo order with no attempt is its own kind of row, never somebody's try. */
    public function testADemoOrderWithNoAttemptIsUnmatched(): void
    {
        $try = new PracticeTry(1, null, [
            new DemoOutcome($this->demo(), new ComparisonScore(72.0, null, [])),
        ]);

        self::assertTrue($try->isUnmatched());
        self::assertFalse($try->isPending());
    }

    /** The unmatched group says so in its own name, rather than borrowing anybody's. */
    public function testTheUnmatchedGroupIsLabelledAndKeyedAsItself(): void
    {
        $group = new AttemptGroup(null, null, null, []);

        self::assertTrue($group->isUnmatched());
        self::assertSame('Unmatched', $group->label());
        self::assertSame('unmatched', $group->key());
    }

    /**
     * An attempt outlives the account that made it, and the page still has to name somebody.
     *
     * "AGENT #7" is a true statement; a blank is not, and the name of whoever is nearby would be a
     * lie. The realm is part of it because the three realms number their users separately.
     */
    public function testAnActorWithNoSurvivingNameIsNamedByRealmAndId(): void
    {
        $group = new AttemptGroup(InitiatorType::Agent, 7, null, []);

        self::assertSame(InitiatorType::Agent->label() . ' #7', $group->label());
        self::assertSame('AGENT:7', $group->key());
    }

    public function testAGroupReportsTheBestScoreAnybodyReached(): void
    {
        $group = new AttemptGroup(InitiatorType::Agent, 7, 'terese', [
            new PracticeTry(1, $this->attempt(1, InitiatorType::Agent, 7), [
                new DemoOutcome($this->demo(1), new ComparisonScore(40.0, null, [])),
            ]),
            new PracticeTry(2, $this->attempt(2, InitiatorType::Agent, 7), [
                new DemoOutcome($this->demo(2), new ComparisonScore(88.0, null, [])),
            ]),
            new PracticeTry(3, $this->attempt(3, InitiatorType::Agent, 7), []),
        ]);

        self::assertSame(3, $group->tryCount());
        self::assertSame(88, $group->bestPercent());
        self::assertSame('terese', $group->label());
    }

    /** Nobody has scored yet: the summary says nothing rather than zero, which would read as a fail. */
    public function testAGroupWithNoScoresYetReportsNoBest(): void
    {
        $group = new AttemptGroup(InitiatorType::Agent, 7, 'terese', [
            new PracticeTry(1, $this->attempt(1, InitiatorType::Agent, 7), []),
        ]);

        self::assertNull($group->bestPercent());
    }

    private function attempt(int $id, InitiatorType $type, int $actorId): TestAttempt
    {
        $at = new DateTimeImmutable('2026-10-07 13:00:00', new DateTimeZone('UTC'));

        return new TestAttempt(
            $id,
            str_pad((string) $id, 32, '0', STR_PAD_LEFT),
            1731,
            16800401,
            $type,
            $actorId,
            AttemptStatus::Expired,
            $at,
            $at->modify('+1 hour'),
        );
    }

    private function demo(int $n = 1): \App\OrderTesting\Domain\DemoOrder
    {
        return new \App\OrderTesting\Domain\DemoOrder(
            1731,
            16800401,
            16630800 + $n,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            'fixture.json',
            null,
            str_repeat('0', 64),
            '{}',
        );
    }
}
