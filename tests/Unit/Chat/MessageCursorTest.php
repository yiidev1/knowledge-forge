<?php

declare(strict_types=1);

namespace App\Tests\Unit\Chat;

use App\Chat\Domain\NewMessage;
use App\Tests\Support\Fake\Chat\InMemoryMessageRepository;
use App\Tests\Support\MutableClock;
use Codeception\Test\Unit;

use function array_map;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

final class MessageCursorTest extends Unit
{
    public function testFindBeforeUsesStableOrderAndDoesNotSkip(): void
    {
        $repo = new InMemoryMessageRepository();
        $clock = new MutableClock();

        $ids = [];
        for ($i = 0; $i < 10; $i++) {
            $ids[] = $repo->add(NewMessage::user(1, 'm' . $i), $clock->now());
            $clock->advance('+1 second');
        }

        $page = $repo->findBefore(1, $ids[7], 3);
        assertSame(['m4', 'm5', 'm6'], array_map(static fn($m) => $m->content, $page ?? []));

        $next = $repo->findBefore(1, $page[0]->id, 3);
        assertSame(['m1', 'm2', 'm3'], array_map(static fn($m) => $m->content, $next ?? []));
    }

    public function testForeignCursorReturnsNull(): void
    {
        $repo = new InMemoryMessageRepository();
        $clock = new MutableClock();
        $id = $repo->add(NewMessage::user(1, 'only'), $clock->now());

        assertNull($repo->findBefore(2, $id, 5));
    }
}
