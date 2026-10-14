<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Web\Actor\CurrentActor;
use PHPUnit\Framework\TestCase;

/**
 * The third realm, and the rule that makes three realms safe: exactly one id is ever set.
 *
 * Operation users and agents share Order58's id sequence. If a holder could end a request carrying
 * both, or carrying an id left behind by an earlier `set*` call, an attempt would be attributed to a
 * realm nobody acted in — and nothing in the row would say so afterwards.
 */
final class CurrentActorAgentRealmTest extends TestCase
{
    public function testAnAgentIsItsOwnRealm(): void
    {
        $actor = new CurrentActor();
        $actor->setAgent(42, 'Dana');

        self::assertTrue($actor->isAgent());
        self::assertFalse($actor->isAdmin());
        self::assertFalse($actor->isOperation());
        self::assertSame(CurrentActor::REALM_AGENT, $actor->realm());
        self::assertSame(42, $actor->agentId());
        self::assertSame('Dana', $actor->displayName());
    }

    /** The agent id is not the operation id, even though both are Order58 `admin_id` values. */
    public function testAnAgentCarriesNoOtherRealmsId(): void
    {
        $actor = new CurrentActor();
        $actor->setAgent(42, 'Dana');

        self::assertNull($actor->adminId());
        self::assertNull($actor->operationId());
    }

    /** @dataProvider overwrites */
    public function testSettingOneRealmClearsTheOthers(string $first, string $second): void
    {
        $actor = new CurrentActor();
        self::apply($actor, $first, 7);
        self::apply($actor, $second, 9);

        $ids = [
            CurrentActor::REALM_ADMIN => $actor->adminId(),
            CurrentActor::REALM_OPERATION => $actor->operationId(),
            CurrentActor::REALM_AGENT => $actor->agentId(),
        ];

        foreach ($ids as $realm => $id) {
            if ($realm === $second) {
                self::assertSame(9, $id, $realm . ' should hold the id it was just given');

                continue;
            }

            self::assertNull($id, $realm . ' must have been cleared by ' . $second);
        }

        self::assertSame($second, $actor->realm());
    }

    /** @return iterable<string, array{string, string}> */
    public static function overwrites(): iterable
    {
        $realms = [CurrentActor::REALM_ADMIN, CurrentActor::REALM_OPERATION, CurrentActor::REALM_AGENT];

        foreach ($realms as $first) {
            foreach ($realms as $second) {
                yield $first . ' then ' . $second => [$first, $second];
            }
        }
    }

    public function testAnUnresolvedActorRefusesToGuess(): void
    {
        $actor = new CurrentActor();

        self::assertFalse($actor->isAgent());
        $this->expectExceptionMessage('No actor has been resolved');
        $actor->realm();
    }

    private static function apply(CurrentActor $actor, string $realm, int $id): void
    {
        match ($realm) {
            CurrentActor::REALM_ADMIN => $actor->setAdmin($id, 'A'),
            CurrentActor::REALM_OPERATION => $actor->setOperation($id, 'O'),
            CurrentActor::REALM_AGENT => $actor->setAgent($id, 'G'),
        };
    }
}
