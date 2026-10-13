<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\AudioToText\Domain\AudioActor;
use App\AudioToText\Domain\AudioActorType;
use App\AudioToText\Infrastructure\CurrentActorAudioActor;
use App\OrderTesting\Domain\InitiatorType;
use App\OrderTesting\Infrastructure\CurrentActorTestActor;
use App\Shared\Web\Actor\CurrentActor;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * One signed-in person, read through two modules' ports.
 *
 * The thing worth pinning is that **an administrator id and an Order58 id never meet**. They are
 * different people even when the number matches, so every assertion here is as much about the column
 * that stays null as the one that is filled.
 */
final class CurrentActorTest extends TestCase
{
    public function testAnAdministratorIsReadAsOneByBothModules(): void
    {
        $holder = new CurrentActor();
        $holder->setAdmin(7, 'alice');

        $audio = AudioActor::fromPort(new CurrentActorAudioActor($holder));
        self::assertSame(AudioActorType::Admin, $audio->type);
        self::assertSame(7, $audio->adminId);
        self::assertNull($audio->operationId, 'an administrator must not populate the operation column');

        $testing = new CurrentActorTestActor($holder);
        self::assertSame(InitiatorType::Admin, $testing->initiatorType());
        self::assertSame(7, $testing->initiatorId());
    }

    public function testAnOperationUserIsReadAsOneByBothModules(): void
    {
        $holder = new CurrentActor();
        $holder->setOperation(472, 'Op User');

        $audio = AudioActor::fromPort(new CurrentActorAudioActor($holder));
        self::assertSame(AudioActorType::Operation, $audio->type);
        self::assertSame(472, $audio->operationId);
        self::assertNull($audio->adminId, 'an operation user must never occupy the administrator column');

        $testing = new CurrentActorTestActor($holder);
        self::assertSame(InitiatorType::Operation, $testing->initiatorType());
        self::assertSame(472, $testing->initiatorId());
    }

    /**
     * The same number in two realms stays two people.
     *
     * This is the misattribution the whole pair-of-columns design exists to prevent, so it is asserted
     * directly rather than left implied by the two tests above.
     */
    public function testTheSameNumberInEachRealmIsNotTheSamePerson(): void
    {
        $holder = new CurrentActor();

        $holder->setAdmin(7, 'alice');
        $asAdmin = AudioActor::fromPort(new CurrentActorAudioActor($holder));

        $holder->setOperation(7, 'Someone Else');
        $asOperation = AudioActor::fromPort(new CurrentActorAudioActor($holder));

        self::assertSame(7, $asAdmin->adminId);
        self::assertSame(7, $asOperation->operationId);
        self::assertNotSame($asAdmin->type, $asOperation->type);
        self::assertNull($asAdmin->operationId);
        self::assertNull($asOperation->adminId);
    }

    /** Switching realms within one request clears the other id rather than leaving it behind. */
    public function testSwitchingRealmsNeverLeavesBothIdsSet(): void
    {
        $holder = new CurrentActor();
        $holder->setAdmin(7, 'alice');
        $holder->setOperation(472, 'Op User');

        self::assertTrue($holder->isOperation());
        self::assertFalse($holder->isAdmin());
        self::assertNull($holder->adminId());

        $holder->setAdmin(9, 'bob');
        self::assertTrue($holder->isAdmin());
        self::assertNull($holder->operationId());
    }

    /**
     * Reading an actor that no gate established is a wiring mistake, not a state to tolerate.
     *
     * It means a route was registered outside the middleware that resolves the actor — returning a
     * null or a zero there would write an untraceable row instead of failing.
     */
    public function testReadingAnUnresolvedActorThrows(): void
    {
        $holder = new CurrentActor();

        self::assertFalse($holder->isResolved());
        $this->expectException(LogicException::class);
        $holder->realm();
    }

    /** @dataProvider nonPositiveIds */
    public function testAnActorCannotBeBuiltFromANonPositiveId(int $id): void
    {
        $this->expectException(InvalidArgumentException::class);
        AudioActor::admin($id);
    }

    /** @return list<array{0: int}> */
    public static function nonPositiveIds(): array
    {
        return [[0], [-1]];
    }

    public function testAnOperationActorAlsoRefusesANonPositiveId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AudioActor::operation(0);
    }

    /** The single-column tables take whichever id the realm filled, never a guess. */
    public function testThePolymorphicIdFollowsTheRealm(): void
    {
        self::assertSame(7, AudioActor::admin(7)->id());
        self::assertSame(472, AudioActor::operation(472)->id());
    }
}
