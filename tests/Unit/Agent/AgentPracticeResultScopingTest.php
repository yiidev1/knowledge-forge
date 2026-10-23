<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use App\Agent\Web\PracticeOrders\CompareAction;
use App\Agent\Web\PracticeOrders\DemoOrdersAction;
use App\Agent\Web\PracticeOrders\DemoUrlAction;
use App\Agent\Web\PracticeOrders\PracticeAttemptsAction;
use App\Agent\Web\PracticeOrders\ReceiptAction;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Every agent page that can reach one demo order must narrow itself to the reader.
 *
 * ## Why this is asserted structurally
 *
 * Four pages on the practice surface are addressed by a demo order's id or list its rows, and each
 * one inherits its whole behaviour from the management page of the same name. That inheritance is the
 * point — it is what keeps one comparison, one receipt and one list in the application instead of
 * two — but it also means an agent page is read-everything *by default*. The narrowing is one
 * overridden method per page, and a page that quietly stopped overriding it would still render, still
 * pass every test about what it looks like, and hand a trainee somebody else's work.
 *
 * So the override itself is the assertion: `getDeclaringClass()` must name the agent class, not the
 * management class it extends. Adding a fifth such page and forgetting to scope it fails here.
 *
 * It does not assert *what* the override returns — {@see \App\Tests\Integration\OrderTesting\PracticeResultVisibilityTest}
 * does that, against the real tables, because the answer is a join across two of them.
 */
final class AgentPracticeResultScopingTest extends TestCase
{
    /**
     * The comparison, the receipt and the flat list, each narrowed by its own override.
     *
     * @return iterable<string, array{0: class-string, 1: string}>
     */
    public static function scopedPages(): iterable
    {
        yield 'comparison' => [CompareAction::class, 'admits'];
        yield 'receipt' => [ReceiptAction::class, 'admits'];
        yield 'all demo orders' => [DemoOrdersAction::class, 'visibleTo'];
        // The history page narrows in the query instead, through the actor filter it pushes down.
        yield 'practice attempts' => [PracticeAttemptsAction::class, 'actorFilter'];
        /*
         * Not a scoping override but the same kind of mistake if it goes missing.
         *
         * The tracked Practice Order endpoint answers a refusal, and a script-less submission, with a
         * redirect and a page of links. Inherited, both named Manage Orders — an address this realm's
         * gate refuses — so a failure took an agent somewhere unrelated to what they were doing.
         */
        yield 'practice order endpoint' => [DemoUrlAction::class, 'surfaceRoutes'];
    }

    /**
     * @param class-string $class
     *
     * @dataProvider scopedPages
     */
    public function testEachPageDeclaresItsOwnScoping(string $class, string $method): void
    {
        $declared = (new ReflectionMethod($class, $method))->getDeclaringClass()->getName();

        self::assertSame(
            $class,
            $declared,
            $class . ' must declare ' . $method . '() itself — inheriting it shows every practitioner',
        );
    }

    /**
     * And the management pages must NOT, which is the other half of the rule.
     *
     * A trainer compares practitioners; that is what the management surface is for. If one of these
     * ever grew its own narrowing, Manage Orders would quietly stop showing a trainer the work they
     * are there to read — the opposite failure, and just as silent.
     *
     * @return iterable<string, array{0: class-string, 1: string}>
     */
    public static function unscopedPages(): iterable
    {
        yield 'comparison' => [\App\OrderTesting\Web\Compare\Action::class, 'admits'];
        yield 'receipt' => [\App\OrderTesting\Web\Receipt\Action::class, 'admits'];
        yield 'all demo orders' => [\App\OrderTesting\Web\DemoOrders\Action::class, 'visibleTo'];
    }

    /**
     * @param class-string $class
     *
     * @dataProvider unscopedPages
     */
    public function testTheManagementPagesAnswerForEverybody(string $class, string $method): void
    {
        $reflected = new ReflectionMethod($class, $method);

        self::assertSame($class, $reflected->getDeclaringClass()->getName());
        self::assertTrue($reflected->isProtected(), $method . '() is a seam, not a public endpoint');
    }
}
