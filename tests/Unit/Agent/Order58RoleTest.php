<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use App\Agent\Domain\Order58Role;
use PHPUnit\Framework\TestCase;

/**
 * The allowlist that decides who may sign in at `/agent/login`, and what they get.
 *
 * Every value here is a real `user_type` from the mirrored Order58 directory, including the mixed-case
 * `PreTraining`. The point of the test is less that the two admitted roles work than that **the other
 * six are refused**, and that a type nobody has seen yet is refused by default.
 */
final class Order58RoleTest extends TestCase
{
    /** @dataProvider admittedTypes */
    public function testAnAdmittedTypeResolvesToItsRole(string $userType, Order58Role $expected): void
    {
        self::assertSame($expected, Order58Role::fromUserType($userType));
    }

    /** @return array<string, array{0: string, 1: Order58Role}> */
    public static function admittedTypes(): array
    {
        return [
            'agent' => ['agent', Order58Role::Agent],
            'operation' => ['operation', Order58Role::Operation],
        ];
    }

    /**
     * The six types the live mirror holds that may not sign in, plus two that do not exist at all.
     *
     * @dataProvider refusedTypes
     */
    public function testEveryOtherTypeIsRefused(string $userType): void
    {
        self::assertNull(Order58Role::fromUserType($userType));
    }

    /** @return list<array{0: string}> */
    public static function refusedTypes(): array
    {
        return [
            ['admin'], ['merchant'], ['trainee'], ['trainer'], ['employee'], ['PreTraining'],
            // Not in the mirror today. A type the provider adds later must be refused until somebody
            // admits it deliberately, which is why the match is an allowlist and not a denylist.
            ['supervisor'], [''],
        ];
    }

    /**
     * Case matters, and this is the test that says so.
     *
     * `PreTraining` is real data. If the comparison were lower-cased to be "forgiving", `PRETRAINING`
     * and `Operation` would both start matching, and a role nobody approved would be admitted by a
     * change that looked like tidying.
     *
     * @dataProvider wrongCasings
     */
    public function testCaseIsNotNormalised(string $userType): void
    {
        self::assertNull(Order58Role::fromUserType($userType));
    }

    /** @return list<array{0: string}> */
    public static function wrongCasings(): array
    {
        return [['Agent'], ['AGENT'], ['Operation'], ['OPERATION'], ['pretraining']];
    }

    public function testOnlyOperationReachesOrderTesting(): void
    {
        self::assertTrue(Order58Role::Operation->canAccessOrderTesting());
        self::assertFalse(Order58Role::Agent->canAccessOrderTesting());
    }

    /** The mirror image: Order Testing is not a second agent area, and the agent app is not an operation one. */
    public function testOnlyAgentsReachTheAgentArea(): void
    {
        self::assertTrue(Order58Role::Agent->canAccessAgentArea());
        self::assertFalse(Order58Role::Operation->canAccessAgentArea());
    }

    /** Each role lands somewhere it is actually allowed to be. */
    public function testEachRoleHasItsOwnHome(): void
    {
        self::assertSame('agent.home', Order58Role::Agent->homeRoute());
        self::assertSame('order-testing', Order58Role::Operation->homeRoute());
    }

    /**
     * Two roles, and that is the whole list.
     *
     * Asserted exactly so that admitting a third — which would widen who can sign in at `/agent/login` —
     * cannot happen without this line changing too.
     */
    public function testTheAllowlistIsExactlyTwoRoles(): void
    {
        self::assertSame(
            ['agent', 'operation'],
            array_map(static fn(Order58Role $r): string => $r->value, Order58Role::cases()),
        );
    }
}
