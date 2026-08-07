<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Auth\Infrastructure\DbAdminUserRepository;
use App\Auth\Infrastructure\NativePasswordHasher;
use App\KnowledgeBase\Infrastructure\DbKnowledgeBaseSourceRepository;
use App\Shared\Domain\Clock\SystemClock;
use App\Tests\Support\IntegrationDb;
use App\Tests\Support\WebTester;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Server-side hard-block of the admin chat GET for an ineligible Order58 store: a direct URL to a
 * source-inactive store's chat cannot open it — it redirects to the store-chat picker. (POST is separately
 * blocked by the canonical policy; unit tests cover that path.)
 */
final class Order58ChatGateCest
{
    private const USERNAME = '__kf_chatgate_admin__';
    private const PASSWORD = 'ChatGatePassw0rd!secure';
    private const SLUG = 'zzgate-inactive-store';
    private const STORE = 903000701;

    private ConnectionInterface $connection;

    public function _before(WebTester $I): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();
        (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create(self::USERNAME, (new NativePasswordHasher())->hash(self::PASSWORD));

        // An Order58-linked knowledge base whose source store is INACTIVE → never chat-eligible.
        $now = new DateTimeImmutable('2026-02-01', new DateTimeZone('UTC'));
        (new DbKnowledgeBaseSourceRepository($this->connection))
            ->createForSource('zzgate Inactive Store', self::SLUG, 'order58', self::STORE, 'zzgate', false, $now);
    }

    public function _after(WebTester $I): void
    {
        $this->cleanup();
    }

    public function adminGetChatIsHardBlockedForASourceInactiveOrder58Store(WebTester $I): void
    {
        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::USERNAME, 'password' => self::PASSWORD]);
        $I->seeCurrentUrlEquals('/');

        // A direct GET to the ineligible store's chat must not open it — it redirects to the picker.
        $I->amOnPage('/knowledge-bases/' . self::SLUG . '/chat');
        $I->seeInCurrentUrl('/admin/order58/store-chat');
    }

    private function cleanup(): void
    {
        $connection = $this->connection ?? IntegrationDb::connectOrSkip();
        IntegrationDb::cleanup($connection, '{{%knowledge_bases}}', ['slug' => self::SLUG]);
        IntegrationDb::cleanup($connection, '{{%admin_users}}', ['username' => self::USERNAME]);
    }
}
