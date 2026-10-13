<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Domain\AudioActor;
use App\AudioToText\Domain\AudioActorType;
use App\AudioToText\Infrastructure\CurrentActorAudioActor;
use App\Shared\Web\Actor\CurrentActor;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Who the audio module thinks is acting, and what happens when nobody has said.
 *
 * ## The defect this exists for
 *
 * Audio upload and replacement on the Audio-to-Text store page refused every write with
 * "an administrator actor needs a positive admin_users id". Nothing was wrong with the administrator:
 * the routes sat behind `RequireAdminMiddleware`, which filled `CurrentAdmin` and not {@see CurrentActor},
 * so the holder these actions read had never been populated at all.
 *
 * Two things made that hard to see, and both are pinned below. The adapter asked `isOperation()`, which
 * answers false for an unresolved holder exactly as it does for an administrator — so "nobody" and "an
 * administrator" were the same answer. The id then came back null, and the failure surfaced several
 * frames later as a complaint about the id rather than about the wiring.
 */
final class CurrentActorAudioActorTest extends TestCase
{
    public function testAnAdministratorIsReportedWithTheirOwnId(): void
    {
        $actor = new CurrentActor();
        $actor->setAdmin(7, 'admin');

        $port = new CurrentActorAudioActor($actor);

        self::assertSame(AudioActorType::Admin, $port->actorType());
        self::assertSame(7, $port->adminId());
        self::assertNull($port->operationId(), 'An administrator carries no operation id.');
        self::assertSame('admin', $port->displayName());
    }

    /**
     * The same number in the other realm is a different person.
     *
     * `admin_users.id = 7` and Order58's `admin_id = 7` are unrelated accounts, which is the whole
     * reason the holder keeps two fields and a realm rather than one id.
     */
    public function testAnOperationUserIsReportedInTheirOwnRealm(): void
    {
        $actor = new CurrentActor();
        $actor->setOperation(7, 'dannyop');

        $port = new CurrentActorAudioActor($actor);

        self::assertSame(AudioActorType::Operation, $port->actorType());
        self::assertSame(7, $port->operationId());
        self::assertNull($port->adminId(), 'An operation user carries no admin id.');
    }

    /**
     * The regression itself: an unresolved holder must not pass for an administrator.
     *
     * Before the fix this returned `Admin`, and the request went on to build an actor from a null id.
     * It now fails where the mistake actually is, with a message about the missing actor.
     */
    public function testAnUnresolvedActorIsNotMistakenForAnAdministrator(): void
    {
        $port = new CurrentActorAudioActor(new CurrentActor());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/No actor has been resolved/');

        $port->actorType();
    }

    /** And the whole chain the write paths use, end to end, for each realm. */
    public function testTheAudioActorBuiltFromThePortCarriesTheRightRealmAndId(): void
    {
        $admin = new CurrentActor();
        $admin->setAdmin(42, 'admin');
        $fromAdmin = AudioActor::fromPort(new CurrentActorAudioActor($admin));

        self::assertTrue($fromAdmin->isAdmin());
        self::assertSame(42, $fromAdmin->id());

        $operation = new CurrentActor();
        $operation->setOperation(473, 'dannyop');
        $fromOperation = AudioActor::fromPort(new CurrentActorAudioActor($operation));

        self::assertFalse($fromOperation->isAdmin());
        self::assertSame(473, $fromOperation->id());
    }

    /**
     * One holder, one realm at a time.
     *
     * The holder is request-scoped and reused, so a realm that failed to clear the other's id would
     * attribute a write to somebody who was never on the request.
     */
    public function testSwitchingRealmsLeavesNoTraceOfTheOther(): void
    {
        $actor = new CurrentActor();
        $port = new CurrentActorAudioActor($actor);

        $actor->setAdmin(7, 'admin');
        $actor->setOperation(473, 'dannyop');

        self::assertSame(AudioActorType::Operation, $port->actorType());
        self::assertNull($port->adminId());

        $actor->setAdmin(7, 'admin');

        self::assertSame(AudioActorType::Admin, $port->actorType());
        self::assertNull($port->operationId());
    }
}
