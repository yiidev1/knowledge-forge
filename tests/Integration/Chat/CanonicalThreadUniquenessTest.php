<?php

declare(strict_types=1);

namespace App\Tests\Integration\Chat;

use App\Chat\Application\FindOrCreateThreadService;
use App\Chat\Domain\ChatParticipant;
use App\Chat\Infrastructure\DbConversationRepository;
use App\Environment;
use App\Shared\Domain\Clock\SystemClock;
use App\Shared\Infrastructure\Db\DbConnectionFactory;
use App\Shared\Infrastructure\Db\DbDateTime;
use App\Shared\Infrastructure\Db\DbParams;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Cache\ArrayCache;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Exception\IntegrityException;

use function PHPUnit\Framework\assertSame;

/**
 * Two separate MySQL connections racing findOrCreate must yield one canonical conversation.
 */
final class CanonicalThreadUniquenessTest extends Unit
{
    private const SLUG = '__kf_test_chat_unique__';
    private const AGENT = 90011;

    protected function _before(): void
    {
        $connection = IntegrationDb::connectOrSkip();
        $this->cleanup($connection);
    }

    protected function _after(): void
    {
        $this->cleanup(IntegrationDb::connectOrSkip());
    }

    public function testConcurrentCreateResolvesToOneThread(): void
    {
        $a = $this->newConnection();
        $b = $this->newConnection();
        $kbId = $this->insertKnowledgeBase($a);
        $clock = new SystemClock();
        $participant = ChatParticipant::agent(self::AGENT);
        $serviceA = new FindOrCreateThreadService(new DbConversationRepository($a), $clock);
        $serviceB = new FindOrCreateThreadService(new DbConversationRepository($b), $clock);

        $first = $serviceA->findOrCreate($kbId, $participant, 'Store');
        $second = $serviceB->findOrCreate($kbId, $participant, 'Store');

        assertSame($first->id, $second->id);

        $count = (int) $a->createCommand(
            'SELECT COUNT(*) FROM {{%conversations}} WHERE [[knowledge_base_id]] = :kb'
            . " AND [[participant_type]] = 'agent' AND [[participant_id]] = :agent",
            [':kb' => $kbId, ':agent' => self::AGENT],
        )->queryScalar();
        assertSame(1, $count);
    }

    public function testDuplicateInsertThrowsAndReselectWorks(): void
    {
        $connection = IntegrationDb::connectOrSkip();
        $kbId = $this->insertKnowledgeBase($connection);
        $repo = new DbConversationRepository($connection);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $admin = ChatParticipant::admin(1);
        $repo->createThread($kbId, $admin, 'Admin', $now);

        try {
            $repo->createThread($kbId, $admin, 'Admin again', $now);
            $this->fail('Expected IntegrityException');
        } catch (IntegrityException) {
            $thread = $repo->findThread($kbId, $admin);
            assertSame(true, $thread !== null);
        }

        $count = (int) $connection->createCommand(
            'SELECT COUNT(*) FROM {{%conversations}} WHERE [[knowledge_base_id]] = :kb'
            . " AND [[participant_type]] = 'admin' AND [[participant_id]] = 1",
            [':kb' => $kbId],
        )->queryScalar();
        assertSame(1, $count);
    }

    public function testAdminAndAgentSameNumericIdDoNotCollide(): void
    {
        $connection = IntegrationDb::connectOrSkip();
        $kbId = $this->insertKnowledgeBase($connection);
        $repo = new DbConversationRepository($connection);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $adminId = $repo->createThread($kbId, ChatParticipant::admin(1), 'Admin', $now);
        $agentId = $repo->createThread($kbId, ChatParticipant::agent(1), 'Agent', $now);

        assertSame(false, $adminId === $agentId);
    }

    private function newConnection(): \Yiisoft\Db\Connection\ConnectionInterface
    {
        $params = new DbParams(
            host: Environment::string('DB_HOST'),
            port: Environment::int('DB_PORT'),
            name: Environment::string('DB_NAME'),
            user: Environment::string('DB_USER'),
            password: Environment::string('DB_PASSWORD'),
            charset: Environment::string('DB_CHARSET'),
            socket: Environment::string('DB_SOCKET'),
        );

        return (new DbConnectionFactory($params, new SchemaCache(new ArrayCache())))->create();
    }

    private function insertKnowledgeBase(\Yiisoft\Db\Connection\ConnectionInterface $connection): int
    {
        $ts = DbDateTime::format(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $connection->createCommand()->insert('{{%knowledge_bases}}', [
            'name' => 'Chat unique KB',
            'slug' => self::SLUG,
            'vector_store_status' => 'ready',
            'status' => 'active',
            'created_at' => $ts,
            'updated_at' => $ts,
        ])->execute();

        return (int) $connection->getLastInsertId();
    }

    private function cleanup(\Yiisoft\Db\Connection\ConnectionInterface $connection): void
    {
        IntegrationDb::cleanup($connection, '{{%knowledge_bases}}', ['slug' => self::SLUG]);
        $connection->createCommand()
            ->delete('{{%conversations}}', [
                'participant_type' => 'agent',
                'participant_id' => self::AGENT,
            ])
            ->execute();
    }
}
