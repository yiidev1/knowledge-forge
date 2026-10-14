<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Domain\AssignedTopic;
use PHPUnit\Framework\TestCase;

/**
 * What a practice-orders row says about the topic it carries.
 *
 * The bug this pins is a row contradicting the picker: "no longer offered" printed beside a topic the
 * dialog is still offering, or the other way round. Both answers now come from the one stored status,
 * so they cannot drift — and the row says *which* of the two unavailable states it is, because an
 * administrator deciding what to do about it needs to know.
 */
final class AssignedTopicTest extends TestCase
{
    public function testAnActiveTopicIsAssignableAndCarriesNoNote(): void
    {
        $topic = new AssignedTopic(1, 'Change Dish', AssignedTopic::ACTIVE, 'Customer changes a dish.');

        self::assertTrue($topic->isAssignable());
        self::assertSame('Active', $topic->statusLabel());
        self::assertSame('', $topic->unavailableNote(), 'an active topic must never read as unavailable');
    }

    public function testAnInactiveTopicSaysItIsInactive(): void
    {
        $topic = new AssignedTopic(1, 'Paused', AssignedTopic::INACTIVE);

        self::assertFalse($topic->isAssignable());
        self::assertSame('Inactive', $topic->statusLabel());
        self::assertStringContainsString('Inactive', $topic->unavailableNote());
        self::assertStringContainsString('kept on this order', $topic->unavailableNote());
    }

    /** The storage calls it ARCHIVED; every screen calls it deleted. */
    public function testTheStoredArchiveValueReadsAsDeleted(): void
    {
        $topic = new AssignedTopic(1, 'Gone', AssignedTopic::DELETED);

        self::assertFalse($topic->isAssignable());
        self::assertSame('Deleted', $topic->statusLabel());
        self::assertStringContainsString('Deleted', $topic->unavailableNote());
        self::assertStringNotContainsString('Archiv', $topic->unavailableNote());
    }

    public function testTheDefaultIsActiveAndDescriptionless(): void
    {
        $topic = new AssignedTopic(7, 'Plain');

        self::assertTrue($topic->isAssignable());
        self::assertNull($topic->description);
    }

    /** A status the enum does not know is shown as it is rather than guessed at. */
    public function testAnUnknownStatusIsNeitherAssignableNorInvented(): void
    {
        $topic = new AssignedTopic(1, 'Odd', 'SOMETHING_ELSE');

        self::assertFalse($topic->isAssignable());
        self::assertSame('SOMETHING_ELSE', $topic->statusLabel());
        self::assertSame('', $topic->unavailableNote());
    }
}
