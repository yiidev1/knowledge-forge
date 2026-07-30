<?php

declare(strict_types=1);

namespace App\Tests\Unit\Chat;

use App\Chat\Domain\NewMessage;
use App\Tests\Support\Fake\Chat\InMemoryMessageRepository;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;

use function array_map;
use function count;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertSame;

/**
 * The chat page loads only the newest N messages of a conversation, returned oldest-first, and never
 * mixes in another conversation's messages. This locks the contract both message repositories follow.
 */
final class RecentMessagesQueryTest extends Unit
{
    private const CONV = 1;
    private const OTHER = 2;

    public function testReturnsOnlyTheNewestTenInChronologicalOrder(): void
    {
        $repo = new InMemoryMessageRepository();
        $now = new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC'));

        // 12 messages "m1".."m12" in this conversation.
        for ($i = 1; $i <= 12; $i++) {
            $repo->add(NewMessage::user(self::CONV, 'm' . $i), $now);
        }

        $recent = $repo->findRecentByConversation(self::CONV, 10);
        $contents = array_map(static fn($m): string => $m->content, $recent);

        assertCount(10, $recent);
        // Newest 10 = m3..m12, oldest of that window first.
        assertSame('m3', $contents[0]);
        assertSame('m12', $contents[count($contents) - 1]);
    }

    public function testNeverReturnsAnotherConversationsMessages(): void
    {
        $repo = new InMemoryMessageRepository();
        $now = new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC'));

        $repo->add(NewMessage::user(self::CONV, 'mine'), $now);
        $repo->add(NewMessage::user(self::OTHER, 'theirs'), $now);

        $recent = $repo->findRecentByConversation(self::CONV, 10);

        assertCount(1, $recent);
        assertSame('mine', $recent[0]->content);
    }

    public function testShortConversationReturnsAllInOrder(): void
    {
        $repo = new InMemoryMessageRepository();
        $now = new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC'));

        $repo->add(NewMessage::user(self::CONV, 'a'), $now);
        $repo->add(NewMessage::user(self::CONV, 'b'), $now);

        $recent = $repo->findRecentByConversation(self::CONV, 10);

        assertSame(['a', 'b'], array_map(static fn($m): string => $m->content, $recent));
    }
}
