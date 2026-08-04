<?php

declare(strict_types=1);

namespace App\Tests\Unit\Chat;

use App\Chat\Application\FindOrCreateThreadService;
use App\Chat\Domain\ChatParticipant;
use App\Tests\Support\Fake\Chat\InMemoryConversationRepository;
use App\Tests\Support\MutableClock;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

final class FindOrCreateThreadServiceTest extends Unit
{
    public function testFindDoesNotCreate(): void
    {
        $repo = new InMemoryConversationRepository();
        $service = new FindOrCreateThreadService($repo, new MutableClock());

        assertNull($service->find(1, ChatParticipant::admin(1)));
        assertSame(0, $repo->count());
    }

    public function testFindOrCreateIsIdempotentForAdmin(): void
    {
        $repo = new InMemoryConversationRepository();
        $service = new FindOrCreateThreadService($repo, new MutableClock());
        $admin = ChatParticipant::admin(1);

        $first = $service->findOrCreate(10, $admin, 'Store A');
        $second = $service->findOrCreate(10, $admin, 'Store A again');

        assertSame($first->id, $second->id);
        assertSame(1, $repo->count());
    }

    public function testAdminAndAgentThreadsAreSeparateEvenWithSameNumericId(): void
    {
        $repo = new InMemoryConversationRepository();
        $service = new FindOrCreateThreadService($repo, new MutableClock());

        $admin = $service->findOrCreate(10, ChatParticipant::admin(1), 'Store A');
        $agent = $service->findOrCreate(10, ChatParticipant::agent(1), 'Store A');

        assertSame(false, $admin->id === $agent->id);
        assertSame(2, $repo->count());
    }

    public function testDifferentAdminsSameKbGetDifferentThreads(): void
    {
        $repo = new InMemoryConversationRepository();
        $service = new FindOrCreateThreadService($repo, new MutableClock());

        $a = $service->findOrCreate(10, ChatParticipant::admin(1), 'Store A');
        $b = $service->findOrCreate(10, ChatParticipant::admin(2), 'Store A');

        assertSame(false, $a->id === $b->id);
        assertSame(2, $repo->count());
    }

    public function testDifferentStoresGetDifferentAdminThreads(): void
    {
        $repo = new InMemoryConversationRepository();
        $service = new FindOrCreateThreadService($repo, new MutableClock());
        $admin = ChatParticipant::admin(1);

        $a = $service->findOrCreate(1, $admin, 'A');
        $b = $service->findOrCreate(2, $admin, 'B');

        assertSame(false, $a->id === $b->id);
    }

    public function testDifferentAgentsOnSameStoreGetDifferentThreads(): void
    {
        $repo = new InMemoryConversationRepository();
        $service = new FindOrCreateThreadService($repo, new MutableClock());

        $a = $service->findOrCreate(1, ChatParticipant::agent(10), 'A');
        $b = $service->findOrCreate(1, ChatParticipant::agent(20), 'A');

        assertSame(false, $a->id === $b->id);
    }
}
