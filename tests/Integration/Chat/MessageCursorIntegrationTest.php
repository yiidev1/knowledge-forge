<?php

declare(strict_types=1);

namespace App\Tests\Integration\Chat;

use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\NewMessage;
use App\Chat\Infrastructure\DbConversationRepository;
use App\Chat\Infrastructure\DbMessageRepository;
use App\Shared\Infrastructure\Db\DbDateTime;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;

use function array_map;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

final class MessageCursorIntegrationTest extends Unit
{
    private const SLUG = '__kf_test_msg_cursor__';

    private ConnectionInterface $connection;
    private DbMessageRepository $messages;
    private int $conversationId;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();
        $now = new DateTimeImmutable('2026-03-01 00:00:00', new DateTimeZone('UTC'));
        $kbId = $this->insertKnowledgeBase($now);
        $conversations = new DbConversationRepository($this->connection);
        $this->conversationId = $conversations->createThread(
            $kbId,
            ChatParticipant::admin(1),
            'Cursor',
            $now,
        );
        $this->messages = new DbMessageRepository($this->connection);

        for ($i = 0; $i < 5; $i++) {
            $this->messages->add(
                NewMessage::user($this->conversationId, 'm' . $i),
                $now->modify('+' . $i . ' seconds'),
            );
        }
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    public function testTupleCursorReturnsOlderWindow(): void
    {
        $all = $this->messages->findByConversation($this->conversationId);
        assertSame(['m0', 'm1', 'm2', 'm3', 'm4'], array_map(static fn($m) => $m->content, $all));

        $page = $this->messages->findBefore($this->conversationId, $all[3]->id, 2);
        assertSame(['m1', 'm2'], array_map(static fn($m) => $m->content, $page ?? []));
        assertNull($this->messages->findBefore($this->conversationId + 999, $all[3]->id, 2));
    }

    private function insertKnowledgeBase(DateTimeImmutable $now): int
    {
        $ts = DbDateTime::format($now);
        $this->connection->createCommand()->insert('{{%knowledge_bases}}', [
            'name' => 'Cursor KB',
            'slug' => self::SLUG,
            'vector_store_status' => 'ready',
            'status' => 'active',
            'created_at' => $ts,
            'updated_at' => $ts,
        ])->execute();

        return (int) $this->connection->getLastInsertId();
    }

    private function cleanup(): void
    {
        IntegrationDb::cleanup($this->connection ?? IntegrationDb::connectOrSkip(), '{{%knowledge_bases}}', ['slug' => self::SLUG]);
    }
}
