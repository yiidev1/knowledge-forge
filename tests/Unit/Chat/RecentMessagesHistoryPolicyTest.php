<?php

declare(strict_types=1);

namespace App\Tests\Unit\Chat;

use App\Chat\Application\History\RecentMessagesHistoryPolicy;
use App\Chat\Domain\Message;
use App\Chat\Domain\MessageRole;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertSame;

/**
 * The history policy keeps the newest turns within a message and character budget, in chronological
 * order.
 */
final class RecentMessagesHistoryPolicyTest extends Unit
{
    public function testKeepsOnlyTheNewestMessagesUpToTheLimit(): void
    {
        $policy = new RecentMessagesHistoryPolicy(messageLimit: 2, charLimit: 100000);

        $selected = $policy->select([
            $this->message(1, 'one'),
            $this->message(2, 'two'),
            $this->message(3, 'three'),
        ]);

        assertCount(2, $selected);
        // Chronological order preserved: the two newest are "two" then "three".
        assertSame('two', $selected[0]->text);
        assertSame('three', $selected[1]->text);
    }

    public function testTrimsByCharacterBudgetOldestFirst(): void
    {
        $policy = new RecentMessagesHistoryPolicy(messageLimit: 100, charLimit: 10);

        $selected = $policy->select([
            $this->message(1, 'aaaaaa'),   // 6 chars
            $this->message(2, 'bbbbbb'),   // 6 chars — together exceed 10
        ]);

        // Only the newest fits within the budget.
        assertCount(1, $selected);
        assertSame('bbbbbb', $selected[0]->text);
    }

    public function testAlwaysKeepsAtLeastTheNewestMessage(): void
    {
        $policy = new RecentMessagesHistoryPolicy(messageLimit: 100, charLimit: 1);

        $selected = $policy->select([$this->message(1, 'a very long single message')]);

        assertCount(1, $selected);
    }

    public function testHundredsOfStoredMessagesStillBoundProviderHistory(): void
    {
        $policy = new RecentMessagesHistoryPolicy(messageLimit: 10, charLimit: 8000);
        $history = [];
        for ($i = 1; $i <= 300; $i++) {
            $history[] = $this->message($i, 'msg-' . $i);
        }

        $selected = $policy->select($history);

        assertCount(10, $selected);
        assertSame('msg-291', $selected[0]->text);
        assertSame('msg-300', $selected[9]->text);
    }

    private function message(int $id, string $content): Message
    {
        $now = new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC'));

        return new Message($id, 1, MessageRole::User, $content, [], false, null, null, $now);
    }
}
