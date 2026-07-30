<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Auth\Infrastructure\DbAdminUserRepository;
use App\Auth\Infrastructure\NativePasswordHasher;
use App\Shared\Domain\Clock\SystemClock;
use App\Tests\Support\IntegrationDb;
use App\Tests\Support\WebTester;

/**
 * The chat web flow against the served application. A freshly created knowledge base is still
 * provisioning and has no indexed documents, so chat must be blocked with an explanation rather than
 * offering a question box — proving the readiness guard reaches the UI. (A grounded answer requires a
 * live OpenAI key and is covered by the unit tests against the fake provider.)
 */
final class ChatCest
{
    private const USERNAME = '__kf_chat_admin__';
    private const PASSWORD = 'ChatTestPassw0rd!secure';
    private const KB_NAME = 'ZZ Chat Test KB';
    private const KB_SLUG = 'zz-chat-test-kb';

    public function _before(WebTester $I): void
    {
        $connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        (new DbAdminUserRepository($connection, new SystemClock()))
            ->create(self::USERNAME, (new NativePasswordHasher())->hash(self::PASSWORD));

        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::USERNAME, 'password' => self::PASSWORD]);
        $I->seeCurrentUrlEquals('/');

        $I->amOnPage('/knowledge-bases/create');
        $I->submitForm('.card form', ['name' => self::KB_NAME, 'description' => '', 'system_instructions' => '']);
        $I->seeCurrentUrlEquals('/knowledge-bases/' . self::KB_SLUG);
    }

    public function _after(WebTester $I): void
    {
        $this->cleanup();
    }

    public function chatIsBlockedWhileProvisioning(WebTester $I): void
    {
        $I->amOnPage('/knowledge-bases/' . self::KB_SLUG . '/chat');
        $I->see('Chat');
        $I->see('still being provisioned');
        $I->dontSeeElement('textarea[name=question]');
    }

    private function cleanup(): void
    {
        $connection = IntegrationDb::connectOrSkip();
        IntegrationDb::cleanup($connection, '{{%knowledge_bases}}', ['slug' => self::KB_SLUG]);
        IntegrationDb::cleanup($connection, '{{%admin_users}}', ['username' => self::USERNAME]);
    }
}
